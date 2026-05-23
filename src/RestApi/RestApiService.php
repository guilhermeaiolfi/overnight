<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\ORM\Typecast\CollectionTypecast;
use ON\ORM\Typecast\TypecastException;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Mutation\RestMutationPlanner;
use ON\RestApi\Query\QueryPlanner;
use ON\RestApi\Query\QueryPlannerInterface;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Resolver\DataSourceInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Resolver\TypecastDataSource;
use ON\RestApi\Serialize\CollectionSerializer;
use ON\RestApi\Support\AuthorizationGuard;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiService
{
	public function __construct(
		protected Registry $registry,
		protected DataSourceInterface $dataSource,
		protected QueryPlannerInterface $queryPlanner,
		protected ?EventDispatcherInterface $eventDispatcher = null,
		protected ?HandlerFactory $relationHandlers = null,
		protected ?CollectionTypecast $collectionTypecast = null,
		protected ?CollectionSerializer $collectionSerializer = null,
	) {
	}

	protected function collectionTypecast(): CollectionTypecast
	{
		return $this->collectionTypecast ??= new CollectionTypecast();
	}

	protected function collectionSerializer(): CollectionSerializer
	{
		return $this->collectionSerializer ??= new CollectionSerializer();
	}

	protected function handlerFactory(): HandlerFactory
	{
		if ($this->relationHandlers !== null) {
			return $this->relationHandlers;
		}

		if ($this->queryPlanner instanceof QueryPlanner) {
			return $this->queryPlanner->handlers();
		}

		$sqlDataSource = $this->sqlDataSource();

		if ($sqlDataSource === null) {
			throw RestApiError::serviceUnavailable();
		}

		return new HandlerFactory(
			HandlerRegistry::defaults(),
			$sqlDataSource,
			new SqlQuerySpecCompiler($sqlDataSource->getDatabase(), 100, 1000)
		);
	}

	protected function sqlDataSource(): ?SqlDataSource
	{
		if ($this->dataSource instanceof TypecastDataSource) {
			return $this->dataSource->inner();
		}

		if ($this->dataSource instanceof SqlDataSource) {
			return $this->dataSource;
		}

		return null;
	}

	public function getDataSource(): DataSourceInterface
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
			AuthorizationGuard::assert($event);
			$querySpec = $event->getQuerySpec() ?? $querySpec;

			if ($event->isDefaultPrevented()) {
				return [
					'items' => $this->hydrateRows($collection, $event->getResult() ?? [], $options),
					'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
				];
			}
		}

		$result = $this->queryList($collection, $querySpec);
		if (isset($event)) {
			$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		$result['items'] = $this->hydrateRows($collection, $result['items'] ?? [], $options);

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
			return $this->hydrateRow($collection, $this->queryGet($collection, $identity, $querySpec), $options);
		}

		$event = new ItemGet($collection, $identity->toUrlId(), $params);
		$this->dispatchEvent($event);
		AuthorizationGuard::assert($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;

		if ($event->isDefaultPrevented()) {
			return $this->hydrateRow($collection, $event->getResult(), $options);
		}

		$event->setResult($this->queryGet($collection, $identity, $querySpec));

		return $this->hydrateRow($collection, $event->getResult(), $options);
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

		return $this->hydrateRow($collection, $result, $options) ?? [];
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

		return $this->hydrateRow($collection, $result, $options);
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

		return $this->hydrateRow($collection, $result, $options) ?? [];
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
			return $this->queryPlanner->aggregate($collection, $querySpec);
		}

		$event = new ItemList($collection, $params);
		$this->dispatchEvent($event);
		AuthorizationGuard::assert($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$result = $this->queryPlanner->aggregate($collection, $querySpec);
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

		return $this->computeETag(json_encode(
			$this->serialize($collection, $current),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
		));
	}

	/**
	 * @param string|array<string, mixed> $payload
	 */
	public function unserialize(CollectionInterface $collection, string|array $payload, bool $partial = false): array
	{
		try {
			return $this->collectionSerializer()->unserialize($collection, $payload, $partial);
		} catch (TypecastException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
			]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function serialize(CollectionInterface $collection, array $phpRow): array
	{
		return $this->collectionSerializer()->serialize($collection, $phpRow);
	}

	protected function mutationPlanner(
		MutationQueue $queue,
		CollectionInterface $rootCollection,
		array $rootInput,
		bool $dispatchEvents
	): RestMutationPlanner
	{
		$sqlDataSource = $this->sqlDataSource();

		if ($sqlDataSource === null) {
			throw RestApiError::serviceUnavailable();
		}

		return new RestMutationPlanner(
			$sqlDataSource,
			$this->handlerFactory(),
			$this->eventDispatcher,
			$dispatchEvents,
			$queue,
			$rootCollection,
			new MutationState($rootCollection, $rootInput)
		);
	}

	protected function queryList(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		return $this->queryPlanner->list($collection, $querySpec);
	}

	protected function queryGet(CollectionInterface $collection, PrimaryKeyValue $identity, ?QuerySpec $querySpec = null): ?array
	{
		return $this->queryPlanner->get($collection, $identity, $querySpec);
	}

	public function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
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
		[, $relations] = MutationInput::splitNodeInput($collection, $input);

		foreach ($relations as $relationName => $relationInput) {
			$relation = $collection->relations->get($relationName);
			$targetCollection = $relation->getCollection();
			$relationFiles = is_array($files[$relationName] ?? null) ? $files[$relationName] : [];

			if ($relation->isJunction()) {
				if (!is_array($relationInput) || !MutationInput::isAssociativeArray($relationInput)) {
					continue;
				}

				foreach (MutationInput::normalizeRelationItems($relationInput['create'] ?? []) as $index => $item) {
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

			if (is_array($relationInput) && MutationInput::isAssociativeArray($relationInput)) {
				$input[$relationName] = $this->handleFileUploadsRecursive($targetCollection, $relationInput, $relationFiles);
				continue;
			}

			foreach (MutationInput::normalizeRelationItems($relationInput) as $index => $item) {
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

	protected function shouldHydrate(array $options): bool
	{
		if ($this->shouldSerialize($options)) {
			return true;
		}

		return ! (bool) ($options['raw'] ?? false);
	}

	protected function shouldSerialize(array $options): bool
	{
		return (bool) ($options['serialize'] ?? false);
	}

	protected function hydrateRow(CollectionInterface $collection, ?array $row, array $options): ?array
	{
		if ($row === null) {
			return null;
		}

		if ($this->shouldSerialize($options)) {
			try {
				$row = $this->collectionTypecast()->toPhp($collection, $row);
			} catch (TypecastException $e) {
				throw RestApiError::validationFailed([
					$e->getField() ?? '_root' => [$e->getMessage()],
				]);
			}

			return $this->serialize($collection, $row);
		}

		if (! $this->shouldHydrate($options)) {
			return $row;
		}

		try {
			return $this->collectionTypecast()->toPhp($collection, $row);
		} catch (TypecastException $e) {
			throw RestApiError::validationFailed([
				$e->getField() ?? '_root' => [$e->getMessage()],
			]);
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	protected function hydrateRows(CollectionInterface $collection, array $rows, array $options): array
	{
		if ($this->shouldSerialize($options)) {
			return array_map(
				fn (array $row): array => $this->hydrateRow($collection, $row, $options),
				$rows
			);
		}

		if (! $this->shouldHydrate($options)) {
			return $rows;
		}

		return array_map(
			fn (array $row): array => $this->collectionTypecast()->toPhp($collection, $row),
			$rows
		);
	}
}
