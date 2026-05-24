<?php

declare(strict_types=1);

namespace ON\RestApi\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\RequestComplete;
use ON\RestApi\Payload\DirectusMutationBuilder;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\RestApiService;
use ON\Validation\CollectionValidator;
use ON\Validation\ValidationFailedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RestMiddleware implements MiddlewareInterface
{
	protected CollectionValidator $validator;

	public function __construct(
		protected RestApiService $restApi,
		protected DirectusMutationBuilder $mutationBuilder,
		protected array $options = []
	) {
		$this->validator = new CollectionValidator(
			$options['validationMessages'] ?? [],
			$options['validationLang'] ?? 'en',
		);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$basePath = $this->options['endpointUri'] ?? '/items';
		$path = $request->getUri()->getPath();

		if (!$this->matchesBasePath($path, $basePath)) {
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
		$remainder = $this->extractRemainder($request->getUri()->getPath(), $basePath);
		$remainder = trim($remainder, '/');
		$segments = $remainder !== '' ? explode('/', $remainder) : [];

		if (count($segments) === 0) {
			throw RestApiError::notFound('No collection specified.');
		}

		$collectionName = $segments[0];
		$id = $segments[1] ?? null;

		$collection = $this->restApi->getCollection($collectionName);

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
		$query = $this->getQueryParams($request);
		$querySpec = $this->querySpec($collection, $query);

		// Aggregate responses use the same parsed AST but keep the Directus response shape.
		if ($querySpec->aggregate !== []) {
			$rows = $this->restApi->aggregate($collection, $querySpec);
			return $this->jsonResponse(['data' => $rows]);
		}

		$result = $this->restApi->list($collection, $querySpec, ['serialize' => true]);

		$body = [
			'data' => $result['items'] ?? [],
		];

		$meta = $result['meta'] ?? [];
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
		$item = $this->restApi->get(
			$collection,
			$this->decodeRouteIdentity($collection, $id),
			$this->querySpec($collection, $this->getQueryParams($request)),
			['serialize' => true]
		);
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
		$options = array_merge($this->getMutationOptions($request), ['serialize' => true]);
		$spec = $this->mutationSpec(
			$collection,
			$body,
			'create',
			files: $options['files'] ?? [],
		);
		$created = $this->restApi->create($collection, $spec, $options);

		return $this->jsonResponse(['data' => $created]);
	}

	// ------------------------------------------------------------------
	// PATCH update
	// ------------------------------------------------------------------

	protected function handleUpdate(ServerRequestInterface $request, CollectionInterface $collection, string $id): ResponseInterface
	{
		$body = $this->parseRequestBody($request);
		$body = $this->stripHiddenFields($collection, $body);
		$this->validateInput($collection, $body, true);
		$options = array_merge($this->getMutationOptions($request), ['serialize' => true]);
		$identity = $this->decodeRouteIdentity($collection, $id);
		$spec = $this->mutationSpec(
			$collection,
			$body,
			'update',
			$identity,
			$options['files'] ?? [],
		);
		$result = $this->restApi->update($collection, $identity, $spec, $options);

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
		$deleted = $this->restApi->delete($collection, $this->decodeRouteIdentity($collection, $id), $this->getMutationOptions($request));
		if (!$deleted) {
			throw RestApiError::notFound();
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
			$options = array_merge($this->getMutationOptions($request), ['serialize' => true]);
			$spec = $this->mutationSpec(
				$collection,
				$item,
				'create',
				files: $options['files'] ?? [],
			);

			$results[] = $this->restApi->create($collection, $spec, $options);
		}

		return $this->jsonResponse(['data' => $results]);
	}

	protected function handleBatchUpdate(ServerRequestInterface $request, CollectionInterface $collection): ResponseInterface
	{
		$body = $this->parseRequestBody($request);

		if (!isset($body[0]) || !is_array($body[0])) {
			throw new RestApiError('Batch update expects an array of objects with primary keys.', 'INVALID_PAYLOAD', null, 400);
		}

		$results = [];
		foreach ($body as $item) {
			$identity = $collection->getPrimaryKey()->extractFromInput($item);
			if ($identity === null) {
				$missing = $collection->getPrimaryKey()->getMissingFieldNames($item);
				throw new RestApiError(
					"Each item must include the primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {
				unset($item[$fieldName]);
			}
			foreach ($collection->getPrimaryKey()->getColumns() as $columnName) {
				unset($item[$columnName]);
			}
			$item = $this->stripHiddenFields($collection, $item);
			$this->validateInput($collection, $item, true);
			$options = array_merge($this->getMutationOptions($request), ['serialize' => true]);
			$spec = $this->mutationSpec(
				$collection,
				$item,
				'update',
				$identity,
				$options['files'] ?? [],
			);
			$updated = $this->restApi->update($collection, $identity, $spec, $options);

			$results[] = $updated ?? [];
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
			$identity = is_array($id)
				? $collection->getPrimaryKey()->extractFromInput($id)
				: $this->decodeRouteIdentity($collection, (string) $id);
			if ($identity === null) {
				$missing = is_array($id) ? $collection->getPrimaryKey()->getMissingFieldNames($id) : $collection->getPrimaryKey()->getFieldNames();
				throw new RestApiError(
					"Batch delete item is missing primary key field(s): " . implode(', ', $missing) . '.',
					'MISSING_PRIMARY_KEY',
					$missing[0] ?? null,
					400
				);
			}

			$this->restApi->delete($collection, $identity, $this->getMutationOptions($request));
		}

		return new EmptyResponse(204, ['Cache-Control' => 'no-cache']);
	}

	// ------------------------------------------------------------------
	// ETag handling
	// ------------------------------------------------------------------

	protected function handleConditionalGet(ServerRequestInterface $request, JsonResponse $response): ResponseInterface
	{
		$body = (string) $response->getBody();
		$etag = $this->restApi->computeETag($body);
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

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	protected function validateInput(CollectionInterface $collection, array $input, bool $isPartial = false): void
	{
		try {
			$this->validator->validate($collection, $input, $isPartial);
		} catch (ValidationFailedException $e) {
			throw RestApiError::validationFailed($e->getErrors());
		}
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
		$this->restApi->dispatchEvent($event);
	}

	protected function getMutationOptions(ServerRequestInterface $request): array
	{
		$options = [
			'ifMatch' => $request->getHeaderLine('If-Match') ?: null,
			'files'   => [],
		];

		if (str_contains($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
			$options['files'] = $request->getUploadedFiles();
		}

		return $options;
	}

	protected function querySpec(CollectionInterface $collection, array $query): QuerySpec
	{
		$parser = new DirectusQueryParser(
			defaultLimit: (int) ($this->options['defaultLimit'] ?? 100),
			maxLimit: (int) ($this->options['maxLimit'] ?? 1000)
		);
		$normalizer = new QueryNormalizer($this->options['dynamicVariables'] ?? []);

		return $normalizer->normalize($parser->parse($collection, $query));
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 */
	protected function mutationSpec(
		CollectionInterface $collection,
		array $input,
		string $mode = 'upsert',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
	): \ON\RestApi\Payload\Node\MutationSpec {
		return $this->mutationBuilder->build($collection, $input, $mode, $id, $files);
	}

	protected function getQueryParams(ServerRequestInterface $request): array
	{
		$query = [];
		parse_str($request->getUri()->getQuery(), $query);

		return array_replace($query, $request->getQueryParams());
	}

	protected function matchesBasePath(string $path, string $basePath): bool
	{
		if (str_starts_with($path, $basePath)) {
			return true;
		}

		// When mounted via path middleware, the matched prefix is removed.
		return str_starts_with($path, '/');
	}

	protected function extractRemainder(string $path, string $basePath): string
	{
		if (str_starts_with($path, $basePath)) {
			return substr($path, strlen($basePath));
		}

		return $path;
	}

	protected function decodeRouteIdentity(CollectionInterface $collection, string $id): PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->isComposite()
			? $collection->getPrimaryKey()->getValueFromUrlId($id)
			: new PrimaryKeyValue($collection, [$collection->getPrimaryKey()->getFieldNames()[0] => $id]);
	}
}
