<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Mutation\RestMutationPlanner;
use ON\RestApi\Query\QueryPlanner;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiService
{
	public function __construct(
		protected Registry $registry,
		protected SqlDataSource $dataSource,
		protected SqlQuerySpecCompiler $querySpecCompiler,
		protected ?EventDispatcherInterface $eventDispatcher = null,
		protected ?HandlerFactory $relationHandlers = null
	) {
		$this->relationHandlers ??= HandlerFactory::defaults();
	}

	public function getDataSource(): SqlDataSource
	{
		return $this->dataSource;
	}

	public function getCollection(string|CollectionInterface $collectionName): CollectionInterface
	{
		$collection = $this->registry->getCollection($collectionName);

		if ($collection === null || $collection->isHidden()) {
			throw RestApiError::collectionNotFound(is_string($collectionName) ? $collectionName : $collectionName->getName());
		}

		return $collection;
	}

	public function getCollections(): array
	{
		return $this->registry->getCollections();
	}

	public function list(string|CollectionInterface $collection, QuerySpec $querySpec, array $options = []): array
	{
		$collection = $this->getCollection($collection);
		$params = $this->eventParams($options, $querySpec);

		if ($this->shouldDispatchEvents($params)) {
			$event = new ItemList($collection, $params);
			$this->dispatchEvent($event);
			$this->assertAuthorized($event);
			$querySpec = $event->getQuerySpec() ?? $querySpec;

			if ($event->isDefaultPrevented()) {
				return [
					'items' => $event->getResult() ?? [],
					'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
				];
			}
		}

		$result = $this->queryList($collection, $querySpec);
		if (isset($event)) {
			$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		return $result;
	}

	public function get(
		string|CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		?QuerySpec $querySpec = null,
		array $options = []
	): ?array
	{
		$collection = $this->getCollection($collection);
		$identity = $this->normalizeIdentity($collection, $identity);
		$params = $querySpec === null ? $options : $this->eventParams($options, $querySpec);

		if (! $this->shouldDispatchEvents($params)) {
			return $this->queryGet($collection, $identity, $querySpec);
		}

		$event = new ItemGet($collection, $identity->toUrlId(), $params);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;

		if ($event->isDefaultPrevented()) {
			return $event->getResult();
		}

		$event->setResult($this->queryGet($collection, $identity, $querySpec));
		return $event->getResult();
	}

	public function create(string|CollectionInterface $collection, array $input, array $options = []): array
	{
		$collection = $this->getCollection($collection);
		$dispatchEvents = $this->shouldDispatchEvents($options);
		$input = $this->handleFileUploadsRecursive($collection, $input, $options['files'] ?? []);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, $input, $dispatchEvents);
		$root = $planner->save('create', $collection, $input);

		$result = $this->dataSource->transaction(function () use ($queue, $root): array {
			$queue->execute($this->dataSource);

			return $root?->getRow() ?? [];
		});
		$planner->dispatchAfterEvents();

		return $result;
	}

	public function update(
		string|CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		array $input,
		array $options = []
	): ?array
	{
		$collection = $this->getCollection($collection);
		$identity = $this->normalizeIdentity($collection, $identity);
		$this->checkIfMatch($collection, $identity, $options['ifMatch'] ?? null);
		$dispatchEvents = $this->shouldDispatchEvents($options);
		$input = $this->handleFileUploadsRecursive($collection, $input, $options['files'] ?? []);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, $input, $dispatchEvents);
		$root = $planner->save('update', $collection, $input, $identity);

		$result = $this->dataSource->transaction(function () use ($queue, $root): ?array {
			$queue->execute($this->dataSource);

			return $root?->getRow();
		});
		$planner->dispatchAfterEvents();

		return $result;
	}

	public function upsert(string|CollectionInterface $collection, array $input, array $options = []): array
	{
		$collection = $this->getCollection($collection);
		$primaryKey = $collection->getPrimaryKey();
		$id = $primaryKey->extractFromInput($input);
		if ($id === null) {
			$missing = $primaryKey->getMissingFieldNames($input);
			$field = $missing[0] ?? null;
			throw new RestApiError(
				"Upsert requires primary key field(s): " . implode(', ', $missing) . '.',
				'MISSING_PRIMARY_KEY',
				$field,
				400
			);
		}

		$dispatchEvents = $this->shouldDispatchEvents($options);
		$input = $this->handleFileUploadsRecursive($collection, $input, $options['files'] ?? []);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, $input, $dispatchEvents);
		$root = $planner->save('upsert', $collection, $input, $id);

		$result = $this->dataSource->transaction(function () use ($queue, $root): array {
			$queue->execute($this->dataSource);

			return $root?->getRow() ?? [];
		});
		$planner->dispatchAfterEvents();

		return $result;
	}

	public function delete(
		string|CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		array $options = []
	): bool
	{
		$collection = $this->getCollection($collection);
		$identity = $this->normalizeIdentity($collection, $identity);
		$this->checkIfMatch($collection, $identity, $options['ifMatch'] ?? null);

		$dispatchEvents = $this->shouldDispatchEvents($options);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, [], $dispatchEvents);
		$deleted = $planner->delete($collection, $identity);

		$result = $this->dataSource->transaction(function () use ($queue, $deleted): bool {
			$queue->execute($this->dataSource);

			return $deleted?->getResult() ?? true;
		});
		$planner->dispatchAfterEvents();

		return $result;
	}

	public function aggregate(string|CollectionInterface $collection, QuerySpec $querySpec, array $options = []): array
	{
		$collection = $this->getCollection($collection);
		$params = $this->eventParams($options, $querySpec);

		if (! $this->shouldDispatchEvents($params)) {
			return $this->queryPlanner()->aggregate($collection, $querySpec);
		}

		$event = new ItemList($collection, $params);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$result = $this->queryPlanner()->aggregate($collection, $querySpec);
		$event->setResult($result);

		return $event->getResult() ?? [];
	}

	public function clearCache(): void
	{
		$this->dataSource->clearCache();
	}

	public function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	public function getItemETag(string|CollectionInterface $collection, PrimaryKeyValue|string $identity): string
	{
		$collection = $this->getCollection($collection);
		$identity = $this->normalizeIdentity($collection, $identity);
		$current = $this->get($collection, $identity, null, ['dispatchEvents' => false]);

		if ($current === null) {
			throw RestApiError::notFound();
		}

		return $this->computeETag(json_encode(['data' => $current]));
	}

	protected function mutationPlanner(
		MutationQueue $queue,
		CollectionInterface $rootCollection,
		array $rootInput,
		bool $dispatchEvents
	): RestMutationPlanner
	{
		return new RestMutationPlanner(
			$this->dataSource,
			$this->relationHandlers,
			$this->eventDispatcher,
			$dispatchEvents,
			$queue,
			$rootCollection,
			new MutationState($rootCollection, $rootInput)
		);
	}

	protected function queryPlanner(): QueryPlanner
	{
		return new QueryPlanner($this->dataSource, $this->relationHandlers, $this->querySpecCompiler);
	}

	protected function queryList(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		return $this->queryPlanner()->list($collection, $querySpec);
	}

	protected function queryGet(CollectionInterface $collection, PrimaryKeyValue $identity, ?QuerySpec $querySpec = null): ?array
	{
		return $this->queryPlanner()->get($collection, $identity, $querySpec);
	}

	/**
	 * @return array{0: array, 1: array}
	 */
	protected function splitNodeInput(CollectionInterface $collection, array $input): array
	{
		$scalar = [];
		$relations = [];

		foreach ($input as $key => $value) {
			if ($collection->relations->has((string) $key)) {
				$relations[(string) $key] = $value;
				continue;
			}

			$scalar[$key] = $value;
		}

		return [$scalar, $relations];
	}

	protected function normalizeRelationItems(mixed $input): array
	{
		if (!is_array($input)) {
			return [];
		}

		return $this->isAssociativeArray($input) ? [$input] : $input;
	}

	protected function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	public function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
	}

	protected function assertAuthorized(object $event): void
	{
		if (! $event instanceof AuthorizationAwareEventInterface) {
			return;
		}

		match ($event->getAuthState()) {
			AuthState::Allowed => null,
			AuthState::Unauthenticated => throw RestApiError::unauthenticated(),
			AuthState::Forbidden, AuthState::Pending => throw RestApiError::forbidden(),
		};
	}

	protected function checkIfMatch(CollectionInterface $collection, PrimaryKeyValue $identity, ?string $ifMatch): void
	{
		if ($ifMatch === null || $ifMatch === '') {
			return;
		}

		if ($ifMatch !== $this->getItemETag($collection, $identity)) {
			throw RestApiError::preconditionFailed();
		}
	}

	protected function normalizeIdentity(CollectionInterface $collection, PrimaryKeyValue|string $identity): PrimaryKeyValue
	{
		if ($identity instanceof PrimaryKeyValue) {
			return $identity;
		}

		return PrimaryKeyCriteria::normalize($collection, $identity);
	}

	protected function handleFileUploads(CollectionInterface $collection, array $input, array $files): array
	{
		if ($files === []) {
			return $input;
		}

		$fileFieldTypes = ['file', 'image', 'upload'];

		foreach ($collection->fields as $name => $field) {
			if (! in_array($field->getType(), $fileFieldTypes, true)) {
				continue;
			}

			if (! isset($files[$name])) {
				continue;
			}

			$event = new FileUpload($collection, $name, $files[$name]);
			$this->dispatchEvent($event);

			if ($event->getStoredValue() !== null) {
				$input[$name] = $event->getStoredValue();
			} else {
				throw RestApiError::fileHandlerMissing($name);
			}
		}

		return $input;
	}

	protected function handleFileUploadsRecursive(CollectionInterface $collection, array $input, array $files): array
	{
		if ($files === []) {
			return $input;
		}

		$input = $this->handleFileUploads($collection, $input, $files);
		[, $relations] = $this->splitNodeInput($collection, $input);

		foreach ($relations as $relationName => $relationInput) {
			$relation = $collection->relations->get($relationName);
			$targetCollection = $relation->getCollection();
			$relationFiles = is_array($files[$relationName] ?? null) ? $files[$relationName] : [];

			if ($relation->isJunction()) {
				if (!is_array($relationInput) || !$this->isAssociativeArray($relationInput)) {
					continue;
				}

				foreach ($this->normalizeRelationItems($relationInput['create'] ?? []) as $index => $item) {
					if (!is_array($item)) {
						continue;
					}

					$input[$relationName]['create'][$index] = $this->handleFileUploadsRecursive(
						$targetCollection,
						$item,
						is_array($relationFiles['create'][$index] ?? null) ? $relationFiles['create'][$index] : []
					);
				}

				continue;
			}

			if (is_array($relationInput) && $this->isAssociativeArray($relationInput)) {
				$input[$relationName] = $this->handleFileUploadsRecursive($targetCollection, $relationInput, $relationFiles);
				continue;
			}

			foreach ($this->normalizeRelationItems($relationInput) as $index => $item) {
				if (!is_array($item)) {
					continue;
				}

				$input[$relationName][$index] = $this->handleFileUploadsRecursive(
					$targetCollection,
					$item,
					is_array($relationFiles[$index] ?? null) ? $relationFiles[$index] : []
				);
			}
		}

		return $input;
	}

	protected function eventParams(array $params, QuerySpec $querySpec): array
	{
		$params['querySpec'] = $querySpec;

		return $params;
	}

	protected function shouldDispatchEvents(array $options): bool
	{
		return $this->eventDispatcher !== null && ($options['dispatchEvents'] ?? true);
	}
}

