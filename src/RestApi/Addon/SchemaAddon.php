<?php

declare(strict_types=1);

namespace ON\RestApi\Addon;

use Laminas\Diactoros\Response\JsonResponse;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\RestApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exposes collection schema as REST endpoints.
 *
 * Endpoints:
 *   GET {basePath}/_schema              → list all non-hidden collections
 *   GET {basePath}/_schema/{collection} → get fields and relations for a collection
 */
class SchemaAddon implements RestApiAddonInterface, MiddlewareInterface
{
	protected string $basePath = '/items';

	public function __construct(
		protected RestApiService $restApi
	) {
	}

	public function register(RestApiService $restApi, array $options = []): void
	{
		$this->restApi = $restApi;
		$this->basePath = $options['basePath'] ?? '/items';
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$path = $request->getUri()->getPath();
		$schemaPath = $this->basePath . '/_schema';

		if (!str_starts_with($path, $schemaPath)) {
			return $handler->handle($request);
		}

		if ($request->getMethod() !== 'GET') {
			return new JsonResponse(
				['errors' => [['message' => 'Method not allowed', 'extensions' => ['code' => 'METHOD_NOT_ALLOWED']]]],
				405
			);
		}

		$remainder = trim(substr($path, strlen($schemaPath)), '/');

		if ($remainder === '') {
			return $this->listCollections();
		}

		return $this->getCollection($remainder);
	}

	protected function listCollections(): JsonResponse
	{
		$collections = [];

		foreach ($this->restApi->getCollections() as $collection) {
			if ($collection->isHidden()) {
				continue;
			}

			$collections[] = [
				'name' => $collection->getName(),
				'table' => $collection->getTable(),
				'description' => $collection->getDescription(),
				'fields' => $this->serializeFields($collection),
			];
		}

		return new JsonResponse(['data' => $collections], 200, ['Cache-Control' => 'no-cache']);
	}

	protected function getCollection(string $name): JsonResponse
	{
		try {
			$collection = $this->restApi->getCollection($name);
		} catch (RestApiError) {
			return new JsonResponse(
				['errors' => [['message' => "Collection '{$name}' not found.", 'extensions' => ['code' => 'COLLECTION_NOT_FOUND']]]],
				404
			);
		}

		$data = [
			'name' => $collection->getName(),
			'table' => $collection->getTable(),
			'description' => $collection->getDescription(),
			'fields' => $this->serializeFields($collection),
			'relations' => $this->serializeRelations($collection),
		];

		return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'no-cache']);
	}

	protected function serializeFields(CollectionInterface $collection): array
	{
		$fields = [];
		foreach ($collection->fields as $name => $field) {
			$entry = [
				'name' => $name,
				'type' => null,
				'primaryKey' => $field->isPrimaryKey(),
				'nullable' => $field->isNullable(),
				'hidden' => $field->isHidden(),
			];

			try {
				$entry['type'] = $field->getType();
			} catch (\Throwable) {
			}

			try {
				$entry['description'] = $field->getDescription();
			} catch (\Throwable) {
			}

			$validation = $field->getValidation();
			if ($validation !== null) {
				$entry['validation'] = $validation;
			}

			$fields[] = $entry;
		}
		return $fields;
	}

	protected function serializeRelations(CollectionInterface $collection): array
	{
		$relations = [];
		foreach ($collection->relations as $name => $relation) {
			$relations[] = [
				'name' => $name,
				'collection' => $relation->getCollection(),
				'cardinality' => $relation->getCardinality(),
				'junction' => $relation->isJunction(),
				'innerKey' => $relation->getInnerKey(),
				'outerKey' => $relation->getOuterKey(),
			];
		}
		return $relations;
	}
}
