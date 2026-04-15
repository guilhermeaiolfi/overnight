<?php

declare(strict_types=1);

namespace ON\RestApi\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemDelete;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Event\ItemUpdate;
use ON\RestApi\Event\RequestComplete;
use ON\RestApi\FieldSelector;
use ON\RestApi\Resolver\RestResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Somnambulist\Components\Validation\Factory as ValidationFactory;

class RestMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Registry $registry,
		protected ?RestResolverInterface $resolver,
		protected ?EventDispatcherInterface $eventDispatcher,
		protected array $options = []
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$basePath = $this->options['path'] ?? '/items';
		$path = $request->getUri()->getPath();

		if (!str_starts_with($path, $basePath)) {
			return $handler->handle($request);
		}

		try {
			$response = $this->route($request, $handler, $basePath);
		} catch (RestApiError $e) {
			$response = $this->errorResponse($e);
		} catch (\Throwable $e) {
			$debug = $this->options['debug'] ?? false;
			$internal = new RestApiError(
				$debug ? $e->getMessage() : 'Internal server error.',
				'INTERNAL_ERROR',
				null,
				500,
				$e
			);
			$response = $this->errorResponse($internal);
		}

		$this->dispatchEvent(new RequestComplete($response));

		return $response;
	}

	// ------------------------------------------------------------------
	// Routing
	// ------------------------------------------------------------------

	protected function route(ServerRequestInterface $request, RequestHandlerInterface $handler, string $basePath): ResponseInterface
	{
		$remainder = substr($request->getUri()->getPath(), strlen($basePath));
		$remainder = trim($remainder, '/');
		$segments = $remainder !== '' ? explode('/', $remainder) : [];

		if (count($segments) === 0) {
			throw RestApiError::notFound('No collection specified.');
		}

		$collectionName = $segments[0];
		$id = $segments[1] ?? null;

		$collection = $this->registry->getCollection($collectionName);
		if ($collection === null || $collection->isHidden()) {
			throw RestApiError::collectionNotFound($collectionName);
		}

		if ($this->resolver === null) {
			throw RestApiError::serviceUnavailable();
		}

		$method = strtoupper($request->getMethod());

		return match (true) {
			$method === 'GET' && $id === null => $this->handleList($request, $collection),
			$method === 'GET' && $id !== null => $this->handleGet($request, $collection, $id),
			$method === 'POST' && $id === null => $this->handleCreate($request, $collection),
			$method === 'PATCH' && $id !== null => $this->handleUpdate($request, $collection, $id),
			$method === 'PATCH' && $id === null => $this->handleBatchUpdate($request, $collection),
			$method === 'DELETE' && $id !== null => $this->handleDelete($request, $collection, $id),
			$method === 'DELETE' && $id === null => $this->handleBatchDelete($request, $collection),
			default => throw RestApiError::methodNotAllowed(),
		};
	}

	// ------------------------------------------------------------------
	// GET list / aggregate
	// ------------------------------------------------------------------

	protected function handleList(ServerRequestInterface $request, CollectionInterface $collection): ResponseInterface
	{
		$query = $request->getQueryParams();

		// Parse fields once in the middleware — resolvers receive the normalized structure
		$fieldSelector = new FieldSelector();
		$parsedFields = $fieldSelector->parse($collection, $query['fields'] ?? null);

		// Parse meta param: "total_count,filter_count" → ['total_count', 'filter_count']
		$rawMeta = $query['meta'] ?? null;
		$meta = $rawMeta === null ? [] : (is_array($rawMeta) ? $rawMeta : array_map('trim', explode(',', $rawMeta)));

		$params = [
			'filter'       => $query['filter'] ?? [],
			'sort'         => $query['sort'] ?? null,
			'fields'       => $parsedFields,
			'limit'        => $this->resolveLimit($query),
			'offset'       => isset($query['offset']) ? (int) $query['offset'] : null,
			'page'         => isset($query['page']) ? (int) $query['page'] : null,
			'search'       => $query['search'] ?? null,
			'meta'         => $meta,
			'deep'         => $query['deep'] ?? [],
			'aggregate'    => $query['aggregate'] ?? null,
			'groupBy'      => $query['groupBy'] ?? null,
		];

		// Aggregate shortcut — no event, just return data
		if ($params['aggregate'] !== null) {
			$rows = $this->resolver->aggregate($collection, $params);
			return $this->jsonResponse(['data' => $rows]);
		}

		$event = new ItemList($collection, $params);
		$this->dispatchEvent($event);

		if (!$event->isDefaultPrevented()) {
			$result = $this->resolver->list($collection, $params);
			$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		$body = [
			'data' => $event->getResult() ?? [],
		];

		$meta = $result['meta'] ?? [];
		if ($event->getTotalCount() !== null) {
			$meta['filter_count'] = $event->getTotalCount();
		}
		if (!empty($meta)) {
			$body['meta'] = $meta;
		}

		$response = $this->jsonResponse($body);

		return $this->handleConditionalGet($request, $response);
	}

	// ------------------------------------------------------------------
	// GET single
	// ------------------------------------------------------------------

	protected function handleGet(ServerRequestInterface $request, CollectionInterface $collection, string $id): ResponseInterface
	{
		$query = $request->getQueryParams();

		$fieldSelector = new FieldSelector();
		$parsedFields = $fieldSelector->parse($collection, $query['fields'] ?? null);

		$params = [
			'fields'       => $parsedFields,
			'deep'         => $query['deep'] ?? null,
		];

		$event = new ItemGet($collection, $id, $params);
		$this->dispatchEvent($event);

		if (!$event->isDefaultPrevented()) {
			$item = $this->resolver->get($collection, $id, $params);
			$event->setResult($item);
		}

		$item = $event->getResult();
		if ($item === null) {
			throw RestApiError::notFound();
		}

		$response = $this->jsonResponse(['data' => $item]);

		return $this->handleConditionalGet($request, $response);
	}

	// ------------------------------------------------------------------
	// POST create
	// ------------------------------------------------------------------

	protected function handleCreate(ServerRequestInterface $request, CollectionInterface $collection): ResponseInterface
	{
		$body = $this->parseRequestBody($request);

		// Batch create: array of objects
		if (isset($body[0]) && is_array($body[0])) {
			return $this->handleBatchCreate($request, $collection, $body);
		}

		$body = $this->stripHiddenFields($collection, $body);

		$this->validateInput($collection, $body);

		$body = $this->handleFileUploads($request, $collection, $body);

		[$scalarInput, $nestedInput] = $this->separateRelationData($collection, $body);

		$event = new ItemCreate($collection, $scalarInput);
		$this->dispatchEvent($event);

		if (!$event->isDefaultPrevented()) {
			$created = !empty($nestedInput)
				? $this->resolver->createWithRelations($collection, $event->getInput(), $nestedInput)
				: $this->resolver->create($collection, $event->getInput());
			$event->setResult($created);
		}

		return $this->jsonResponse(['data' => $event->getResult()]);
	}

	// ------------------------------------------------------------------
	// PATCH update
	// ------------------------------------------------------------------

	protected function handleUpdate(ServerRequestInterface $request, CollectionInterface $collection, string $id): ResponseInterface
	{
		$body = $this->parseRequestBody($request);
		$body = $this->stripHiddenFields($collection, $body);

		$this->validateInput($collection, $body, true);

		$this->checkIfMatch($request, $collection, $id);

		[$scalarInput, $nestedInput] = $this->separateRelationData($collection, $body);

		$event = new ItemUpdate($collection, $id, $scalarInput);
		$this->dispatchEvent($event);

		if (!$event->isDefaultPrevented()) {
			$updated = !empty($nestedInput)
				? $this->resolver->updateWithRelations($collection, $id, $event->getInput(), $nestedInput)
				: $this->resolver->update($collection, $id, $event->getInput());
			$event->setResult($updated ?? []);
		}

		$result = $event->getResult();
		if (empty($result)) {
			throw RestApiError::notFound();
		}

		return $this->jsonResponse(['data' => $result]);
	}

	// ------------------------------------------------------------------
	// DELETE
	// ------------------------------------------------------------------

	protected function handleDelete(ServerRequestInterface $request, CollectionInterface $collection, string $id): ResponseInterface
	{
		$this->checkIfMatch($request, $collection, $id);

		$event = new ItemDelete($collection, $id);
		$this->dispatchEvent($event);

		if (!$event->isDefaultPrevented()) {
			$deleted = $this->resolver->delete($collection, $id);
			if (!$deleted) {
				throw RestApiError::notFound();
			}
		}

		return new EmptyResponse(204, ['Cache-Control' => 'no-cache']);
	}

	// ------------------------------------------------------------------
	// Batch operations
	// ------------------------------------------------------------------

	protected function handleBatchCreate(ServerRequestInterface $request, CollectionInterface $collection, array $items): ResponseInterface
	{
		$results = [];
		foreach ($items as $item) {
			$item = $this->stripHiddenFields($collection, $item);
			$this->validateInput($collection, $item);
			$item = $this->handleFileUploads($request, $collection, $item);

			[$scalarInput, $nestedInput] = $this->separateRelationData($collection, $item);

			$event = new ItemCreate($collection, $scalarInput);
			$this->dispatchEvent($event);

			if (!$event->isDefaultPrevented()) {
				$created = !empty($nestedInput)
					? $this->resolver->createWithRelations($collection, $event->getInput(), $nestedInput)
					: $this->resolver->create($collection, $event->getInput());
				$event->setResult($created);
			}

			$results[] = $event->getResult();
		}

		return $this->jsonResponse(['data' => $results]);
	}

	protected function handleBatchUpdate(ServerRequestInterface $request, CollectionInterface $collection): ResponseInterface
	{
		$body = $this->parseRequestBody($request);

		if (!isset($body[0]) || !is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$pk = $collection->getPrimaryKey(true);
		$pkName = is_array($pk) ? $pk[0] : $pk;

		$results = [];
		foreach ($body as $item) {
			$id = (string) ($item[$pkName] ?? '');
			if ($id === '') {
				throw new RestApiError("Each item must include the primary key '{$pkName}'.", 'MISSING_PRIMARY_KEY', $pkName, 400);
			}

			unset($item[$pkName]);
			$item = $this->stripHiddenFields($collection, $item);
			$this->validateInput($collection, $item, true);

			[$scalarInput, $nestedInput] = $this->separateRelationData($collection, $item);

			$event = new ItemUpdate($collection, $id, $scalarInput);
			$this->dispatchEvent($event);

			if (!$event->isDefaultPrevented()) {
				$updated = !empty($nestedInput)
					? $this->resolver->updateWithRelations($collection, $id, $event->getInput(), $nestedInput)
					: $this->resolver->update($collection, $id, $event->getInput());
				$event->setResult($updated ?? []);
			}

			$results[] = $event->getResult();
		}

		return $this->jsonResponse(['data' => $results]);
	}

	protected function handleBatchDelete(ServerRequestInterface $request, CollectionInterface $collection): ResponseInterface
	{
		$body = $this->parseRequestBody($request);

		if (!is_array($body)) {
			throw new RestApiError('Batch delete expects an array of IDs.', 'INVALID_PAYLOAD', null, 400);
		}

		foreach ($body as $id) {
			$id = (string) $id;

			$event = new ItemDelete($collection, $id);
			$this->dispatchEvent($event);

			if (!$event->isDefaultPrevented()) {
				$this->resolver->delete($collection, $id);
			}
		}

		return new EmptyResponse(204, ['Cache-Control' => 'no-cache']);
	}

	// ------------------------------------------------------------------
	// ETag handling
	// ------------------------------------------------------------------

	protected function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	protected function handleConditionalGet(ServerRequestInterface $request, JsonResponse $response): ResponseInterface
	{
		$body = (string) $response->getBody();
		$etag = $this->computeETag($body);
		$response = $response->withHeader('ETag', $etag);

		$ifNoneMatch = $request->getHeaderLine('If-None-Match');
		if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
			return new EmptyResponse(304, [
				'ETag'          => $etag,
				'Cache-Control' => 'no-cache',
			]);
		}

		return $response;
	}

	protected function checkIfMatch(ServerRequestInterface $request, CollectionInterface $collection, string $id): void
	{
		$ifMatch = $request->getHeaderLine('If-Match');
		if ($ifMatch === '') {
			return;
		}

		$current = $this->resolver->get($collection, $id);
		if ($current === null) {
			throw RestApiError::notFound();
		}

		$currentETag = $this->computeETag(json_encode(['data' => $current]));
		if ($ifMatch !== $currentETag) {
			throw RestApiError::preconditionFailed();
		}
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	protected function validateInput(CollectionInterface $collection, array $input, bool $isPartial = false): void
	{
		$rules = [];

		foreach ($collection->fields as $name => $field) {
			$fieldRules = $field->getValidation();
			if ($fieldRules === null) {
				continue;
			}

			// For partial updates, only validate fields present in input
			if ($isPartial && !array_key_exists($name, $input)) {
				continue;
			}

			$rules[$name] = $fieldRules;
		}

		if (empty($rules)) {
			return;
		}

		$factory = new ValidationFactory();
		$validation = $factory->make($input, $rules);
		$validation->validate();

		if ($validation->fails()) {
			throw RestApiError::validationFailed($validation->errors()->toArray());
		}
	}

	// ------------------------------------------------------------------
	// File upload handling
	// ------------------------------------------------------------------

	protected function handleFileUploads(ServerRequestInterface $request, CollectionInterface $collection, array $input): array
	{
		$contentType = $request->getHeaderLine('Content-Type');
		if (!str_contains($contentType, 'multipart/form-data')) {
			return $input;
		}

		$uploadedFiles = $request->getUploadedFiles();
		$fileFieldTypes = ['file', 'image', 'upload'];

		foreach ($collection->fields as $name => $field) {
			if (!in_array($field->getType(), $fileFieldTypes, true)) {
				continue;
			}

			if (!isset($uploadedFiles[$name])) {
				continue;
			}

			$event = new FileUpload($collection, $name, $uploadedFiles[$name]);
			$this->dispatchEvent($event);

			if ($event->getStoredPath() !== null) {
				$input[$name] = $event->getStoredPath();
			} else {
				throw RestApiError::fileHandlerMissing($name);
			}
		}

		return $input;
	}

	// ------------------------------------------------------------------
	// Input processing
	// ------------------------------------------------------------------

	protected function parseRequestBody(ServerRequestInterface $request): array
	{
		$contentType = $request->getHeaderLine('Content-Type');

		if (str_contains($contentType, 'multipart/form-data')) {
			$parsedBody = $request->getParsedBody();
			$data = [];
			if (isset($parsedBody['data'])) {
				$data = json_decode($parsedBody['data'], true);
				if (!is_array($data)) {
					throw RestApiError::invalidJson();
				}
			}
			return $data;
		}

		$raw = (string) $request->getBody();
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw RestApiError::invalidJson();
		}

		return $decoded;
	}

	protected function stripHiddenFields(CollectionInterface $collection, array $input): array
	{
		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden() && array_key_exists($name, $input)) {
				unset($input[$name]);
			}
		}

		return $input;
	}

	/**
	 * Split input into scalar fields and nested relation data.
	 *
	 * @return array{0: array, 1: array} [scalarInput, nestedInput]
	 */
	protected function separateRelationData(CollectionInterface $collection, array $input): array
	{
		$scalar = [];
		$nested = [];

		$relationNames = [];
		foreach ($collection->relations as $name => $relation) {
			$relationNames[$name] = true;
		}

		foreach ($input as $key => $value) {
			if (isset($relationNames[$key]) && is_array($value)) {
				$nested[$key] = $value;
			} else {
				$scalar[$key] = $value;
			}
		}

		return [$scalar, $nested];
	}

	// ------------------------------------------------------------------
	// Response helpers
	// ------------------------------------------------------------------

	protected function jsonResponse(array $data, int $status = 200): JsonResponse
	{
		return new JsonResponse($data, $status, ['Cache-Control' => 'no-cache']);
	}

	protected function errorResponse(RestApiError $error): JsonResponse
	{
		$debug = $this->options['debug'] ?? false;
		return new JsonResponse(
			$error->toArray($debug),
			$error->getHttpStatus(),
			['Cache-Control' => 'no-cache']
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	protected function dispatchEvent(object $event): void
	{
		if ($this->eventDispatcher !== null) {
			$this->eventDispatcher->dispatch($event);
		}
	}

	protected function resolveLimit(array $query): int
	{
		$default = $this->options['defaultLimit'] ?? 100;
		$max = $this->options['maxLimit'] ?? 1000;

		$limit = isset($query['limit']) ? (int) $query['limit'] : $default;

		return min(max($limit, 1), $max);
	}
}
