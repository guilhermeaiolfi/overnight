<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\ORM\Typecast\TypecastException;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerRegistry;
use ON\RestApi\Mutation\FileUploadEventEmitter;
use ON\RestApi\Mutation\MutationPlanner;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Query\QueryPlanner;
use ON\RestApi\Query\QueryPlannerInterface;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Serialize\CollectionSerializer;
use ON\RestApi\Support\AuthorizationGuard;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiService
{
	public function __construct(
		protected Registry $registry,
		protected ItemRepositoryInterface $items,
		protected QueryPlannerInterface $queryPlanner,
		protected ?EventDispatcherInterface $eventDispatcher = null,
		protected ?HandlerFactory $relationHandlers = null,
		protected ?CollectionSerializer $collectionSerializer = null,
		protected ?FileUploadEventEmitter $fileUploadEventEmitter = null,
	) {
	}

	protected function collectionSerializer(): CollectionSerializer
	{
		return $this->collectionSerializer ??= new CollectionSerializer();
	}

	protected function fileUploadEventEmitter(): FileUploadEventEmitter
	{
		return $this->fileUploadEventEmitter ??= new FileUploadEventEmitter($this->registry, $this->eventDispatcher);
	}

	private ?HandlerFactory $handlerFactory = null;

	protected function handlerFactory(): HandlerFactory
	{
		if ($this->relationHandlers !== null) {
			return $this->relationHandlers;
		}

		if ($this->queryPlanner instanceof QueryPlanner) {
			return $this->queryPlanner->handlers();
		}

		return $this->handlerFactory ??= new HandlerFactory(
			HandlerRegistry::defaults(),
			$this->items,
			new SqlQuerySpecCompiler($this->items->getDatabase(), 100, 1000)
		);
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

		if ($this->shouldDispatchEvents($options)) {
			$event = new ItemList($collection, $querySpec, $options);
			$this->dispatchEvent($event);
			AuthorizationGuard::assert($event);
			$querySpec = $event->getQuerySpec();
			$responseOptions = $event->getOptions();

			if ($event->isDefaultPrevented()) {
				return [
					'items' => $this->formatResponseRows($collection, $event->getResult() ?? [], $responseOptions),
					'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
				];
			}
		}

		$result = $this->queryList($collection, $querySpec, $options);
		if (isset($event)) {
			$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
			$responseOptions = $event->getOptions();
		}

		$result['items'] = $this->formatResponseRows(
			$collection,
			$result['items'] ?? [],
			$responseOptions ?? $options
		);

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

		if (! $this->shouldDispatchEvents($options)) {
			return $this->formatResponseRow(
				$collection,
				$this->queryGet($collection, $identity, $querySpec, $options),
				$options
			);
		}

		$event = new ItemGet($collection, $identity, $querySpec, $options);
		$this->dispatchEvent($event);
		AuthorizationGuard::assert($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;
		$responseOptions = $event->getOptions();

		if ($event->isDefaultPrevented()) {
			return $this->formatResponseRow($collection, $event->getResult(), $responseOptions);
		}

		$event->setResult($this->queryGet($collection, $identity, $querySpec, $options));

		return $this->formatResponseRow($collection, $event->getResult(), $responseOptions);
	}

	public function create(string|CollectionInterface $collection, MutationSpec $spec, array $options = []): array
	{
		$collection = $this->getCollection($collection);
		$this->fileUploadEventEmitter()->process($spec);
		$dispatchEvents = $this->shouldDispatchEvents($options);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, $spec, $dispatchEvents);
		$root = $planner->save('create', $collection, $spec);

		$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
		$planner->dispatchAfterEvents();

		return $this->formatResponseRow($collection, $result, $options) ?? [];
	}

	public function update(
		string|CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		MutationSpec $spec,
		array $options = []
	): ?array {
		$collection = $this->getCollection($collection);
		$identity = $this->normalizeIdentity($collection, $identity);
		$this->checkIfMatch($collection, $identity, $options['ifMatch'] ?? null);
		$this->fileUploadEventEmitter()->process($spec);
		$dispatchEvents = $this->shouldDispatchEvents($options);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, $spec, $dispatchEvents);
		$root = $planner->save('update', $collection, $spec, $identity);

		$result = $this->items->commit($queue, fn (): ?array => $root?->getRow());
		$planner->dispatchAfterEvents();

		return $this->formatResponseRow($collection, $result, $options);
	}

	public function upsert(string|CollectionInterface $collection, MutationSpec $spec, array $options = []): array
	{
		$collection = $this->getCollection($collection);
		$primaryKey = $collection->getPrimaryKey();
		$id = $primaryKey->extractFromInput($spec->root->fields);
		if ($id === null) {
			$missing = $primaryKey->getMissingFieldNames($spec->root->fields);
			$field = $missing[0] ?? null;
			throw new RestApiError(
				"Upsert requires primary key field(s): " . implode(', ', $missing) . '.',
				'MISSING_PRIMARY_KEY',
				$field,
				400
			);
		}

		$this->fileUploadEventEmitter()->process($spec);
		$dispatchEvents = $this->shouldDispatchEvents($options);
		$queue = new MutationQueue();
		$planner = $this->mutationPlanner($queue, $collection, $spec, $dispatchEvents);
		$root = $planner->save('upsert', $collection, $spec, $id);

		$result = $this->items->commit($queue, fn (): array => $root?->getRow() ?? []);
		$planner->dispatchAfterEvents();

		return $this->formatResponseRow($collection, $result, $options) ?? [];
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
		$emptySpec = new MutationSpec(new MutationNodeSpec($collection->getName()));
		$planner = $this->mutationPlanner($queue, $collection, $emptySpec, $dispatchEvents);
		$deleted = $planner->delete($collection, $identity);

		$result = $this->items->commit($queue, fn (): bool => $deleted?->getResult() ?? true);
		$planner->dispatchAfterEvents();

		return $result;
	}

	public function aggregate(string|CollectionInterface $collection, QuerySpec $querySpec, array $options = []): array
	{
		$collection = $this->getCollection($collection);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->queryPlanner->aggregate($collection, $querySpec);
		}

		$event = new ItemList($collection, $querySpec, $options);
		$this->dispatchEvent($event);
		AuthorizationGuard::assert($event);
		$querySpec = $event->getQuerySpec();

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$result = $this->queryPlanner->aggregate($collection, $querySpec);
		$event->setResult($result);

		return $event->getResult() ?? [];
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
		MutationSpec $spec,
		bool $dispatchEvents
	): MutationPlanner {
		return new MutationPlanner(
			$this->registry,
			$this->items,
			$this->handlerFactory(),
			$this->eventDispatcher,
			$dispatchEvents,
			$queue,
			$rootCollection,
			new MutationState($rootCollection, $spec->root->fields),
		);
	}

	protected function queryList(CollectionInterface $collection, QuerySpec $querySpec, array $options = []): array
	{
		return $this->queryPlanner->list($collection, $querySpec);
	}

	protected function queryGet(
		CollectionInterface $collection,
		PrimaryKeyValue $identity,
		?QuerySpec $querySpec = null,
		array $options = [],
	): ?array {
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

	protected function shouldDispatchEvents(array $options): bool
	{
		return $this->eventDispatcher !== null && ($options['dispatchEvents'] ?? true);
	}

	protected function shouldSerialize(array $options): bool
	{
		return (bool) ($options['serialize'] ?? false);
	}

	/**
	 * Shape a row for the caller: storage → PHP (unless raw) → wire (when serialize).
	 */
	protected function formatResponseRow(CollectionInterface $collection, ?array $row, array $options): ?array
	{
		if ($row === null) {
			return null;
		}

		if ($row !== [] && ! ($options['raw'] ?? false)) {
			$row = $this->items->hydrateRow($collection, $row);
		}

		if ($this->shouldSerialize($options)) {
			return $this->serialize($collection, $row);
		}

		return $row;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	protected function formatResponseRows(CollectionInterface $collection, array $rows, array $options): array
	{
		if ($rows === []) {
			return $rows;
		}

		return array_map(
			fn (array $row): array => $this->formatResponseRow($collection, $row, $options),
			$rows
		);
	}
}
