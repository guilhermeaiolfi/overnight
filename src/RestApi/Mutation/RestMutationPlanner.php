<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Event\RelationConnected;
use ON\RestApi\Event\RelationConnecting;
use ON\RestApi\Event\RelationDisconnected;
use ON\RestApi\Event\RelationDisconnecting;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\MutationHandlerInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestMutationPlanner
{
	private const ACTIONS = ['create', 'update', 'delete', 'connect', 'disconnect'];
	private const CHILD_ACTIONS = ['create', 'update', 'delete'];

	/** @var list<object> */
	private array $afterEvents = [];

	/** @var list<array{0: CollectionInterface, 1: MutationStateInterface, 2: MutationDeleteTaskInterface, 3: array}> */
	private array $afterDeleteEvents = [];

	public function __construct(
		private SqlDataSource $dataSource,
		private HandlerFactory $handlers,
		private ?EventDispatcherInterface $eventDispatcher,
		private bool $dispatchEvents,
		private MutationQueue $queue,
		private CollectionInterface $rootCollection,
		private MutationStateInterface $rootState
	) {
	}

	/**
	 * @param 'create'|'update'|'upsert' $mode
	 */
	public function save(
		string $mode,
		CollectionInterface $collection,
		array|MutationStateInterface $input,
		PrimaryKeyValue|string|null $id = null,
		array $path = []
	): ?MutationTaskInterface {
		$state = $input instanceof MutationStateInterface ? $input : new MutationState($collection, $input);
		if ($path === [] && $collection === $this->rootCollection) {
			$state = $this->rootState;
			if (is_array($input)) {
				$state->setData($input);
			}
		}

		$root = $this->handlers->root($collection);
		$prevented = null;
		$node = $this->planSaveNode($mode, $root, $state, $id, $path, $prevented);
		if ($node === null) {
			return $prevented;
		}

		return $this->compileNode($node);
	}

	public function delete(
		CollectionInterface $collection,
		PrimaryKeyValue|string $id,
		array $path = []
	): ?MutationDeleteTaskInterface
	{
		$identity = $this->normalizeIdentity($collection, $id);
		$state = new MutationState($collection, $identity->values());
		$handler = $this->handlers->root($collection);
		$node = [
			'handler' => $handler,
			'operation' => 'delete',
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'actions' => $handler->normalizePayload('delete', [], $state, $this->dataSource),
			'relations' => [],
		];

		$beforeEvent = $this->scheduleNodeLifecycle($node);
		if ($beforeEvent instanceof ItemDeleting && $beforeEvent->isDefaultPrevented()) {
			return new MutationDeleteTask(static fn(): bool => $beforeEvent->getPreventedResult());
		}

		$this->compileNode($node);

		return $this->lastDeleteTask();
	}

	public function dispatchAfterEvents(): void
	{
		foreach ($this->afterEvents as $event) {
			$this->dispatchEvent($event);
		}

		foreach ($this->afterDeleteEvents as [$collection, $state, $task, $path]) {
			if (!$task->getResult()) {
				continue;
			}

			$state->markReady($state->getData());
			$this->dispatchEvent(new ItemDeleted($collection, $state, true, $path, $this->rootCollection, $this->rootState));
		}
	}

	/**
	 * @param 'create'|'update'|'upsert' $mode
	 */
	private function planSaveNode(
		string $mode,
		MutationHandlerInterface $handler,
		MutationStateInterface $state,
		PrimaryKeyValue|string|null $id,
		array $path,
		?MutationTaskInterface &$prevented = null
	): ?array {
		$collection = $state->getCollection();
		if ($id === null && $mode !== 'create') {
			$id = $this->getInputPrimaryKeyValue($collection, $state->getData());
		}
		$id = $id === null ? null : $this->normalizeIdentity($collection, $id);
		$operation = $this->resolveOperation($mode, $collection, $id);

		if ($operation === 'update' && $id === null) {
			return null;
		}
		if ($operation === 'update') {
			foreach ($id->values() as $fieldName => $value) {
				$state->setValue($fieldName, $value);
			}
		}

		$node = [
			'handler' => $handler,
			'operation' => $operation,
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'actions' => [],
			'relations' => [],
		];

		$beforeEvent = $this->scheduleNodeLifecycle($node);
		if ($beforeEvent instanceof ItemCreating && $beforeEvent->isDefaultPrevented()) {
			$prevented = new MutationTask(MutationState::fromRow($collection, $beforeEvent->getPreventedResult()));
			return null;
		}

		$this->assertCreateIdAvailable($operation, $collection, $state);

		[$scalarInput, $relationInput] = $this->splitNodeInput($collection, $state->getData());
		$state->setData($scalarInput);
		$node['actions'] = $handler->normalizePayload($operation, $scalarInput, $state, $this->dataSource);
		$node['relations'] = $this->planRelations($operation, $collection, $state, $relationInput, $path);

		$this->scheduleNodeLifecycle($node, true);

		return $node;
	}

	private function planRelations(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $relationInput,
		array $path
	): array {
		$relations = [];
		foreach ($relationInput as $relationName => $rawInput) {
			$handler = $this->handlers->mutation($collection, (string) $relationName);
			if ($handler === null) {
				continue;
			}

			$payload = $handler->normalizePayload($operation, $rawInput, $state, $this->dataSource);
			$children = $this->planRelationChildren($handler, $payload, $rawInput, [...$path, $relationName]);

			$relation = [
				'handler' => $handler,
				'payload' => $payload,
				'children' => $children,
				'path' => [...$path, $relationName],
				'state' => $state,
			];

			$this->scheduleRelationPayloadLifecycle($relation);
			$relations[$relationName] = $relation;
		}

		return $relations;
	}

	private function planRelationChildren(
		MutationHandlerInterface $handler,
		array $payload,
		mixed $rawInput,
		array $path
	): array {
		$children = [
			'create' => [],
			'update' => [],
			'delete' => [],
		];

		foreach (self::CHILD_ACTIONS as $action) {
			foreach ($payload[$action] ?? [] as $index => $item) {
				$child = $this->planRelationChild($handler, $action, $item, $rawInput, $path, $index);
				if ($child !== null) {
					$children[$child['operation']][] = $child;
				}
			}
		}

		return $children;
	}

	private function planRelationChild(
		MutationHandlerInterface $handler,
		string $action,
		mixed $item,
		mixed $rawInput,
		array $path,
		int|string $index
	): ?array {
		if ($action !== 'delete' && !is_array($item)) {
			return null;
		}

		$collection = $handler->mutationCollection($action, $item);
		$childPath = $this->relationChildPath($path, $rawInput, $action, $index);
		$childHandler = $this->handlers->root($collection);

		return match ($action) {
			'create' => $this->planSaveNode('create', $childHandler, new MutationState($collection, $item), null, $childPath),
			'update' => $this->planSaveNode(
				'upsert',
				$childHandler,
				new MutationState($collection, $item),
				$this->getInputPrimaryKeyValue($collection, $item),
				$childPath
			),
			'delete' => $this->planDeleteChild($collection, $item, $childPath, $childHandler),
			default => null,
		};
	}

	private function planDeleteChild(
		CollectionInterface $collection,
		mixed $item,
		array $path,
		MutationHandlerInterface $handler
	): ?array {
		$id = is_array($item) ? $this->getInputPrimaryKeyValue($collection, $item) : $item;
		if ($id === null) {
			return null;
		}

		$identity = $this->normalizeIdentity($collection, $id);
		$state = new MutationState($collection, $identity->values());
		$node = [
			'handler' => $handler,
			'operation' => 'delete',
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'actions' => $handler->normalizePayload('delete', [], $state, $this->dataSource),
			'relations' => [],
		];

		$beforeEvent = $this->scheduleNodeLifecycle($node);
		if ($beforeEvent instanceof ItemDeleting && $beforeEvent->isDefaultPrevented()) {
			return null;
		}

		return $node;
	}

	private function compileNode(array $node): MutationTaskInterface|MutationDeleteTaskInterface|null
	{
		$task = $node['handler']->compileActions($this->queue, $node['state'], $node['actions']);
		$this->storeDeleteTask($node, $task);
		$this->compileRelationPlans($node['state'], $node['relations']);

		return $task;
	}

	private function compileRelationPlans(MutationStateInterface $state, array $relations): void
	{
		foreach ($relations as $relation) {
			$relation['handler']->compileActions(
				$this->queue,
				$state,
				$relation['payload'],
				$this->relationChildStates($relation['children'])
			);

			foreach (self::CHILD_ACTIONS as $action) {
				foreach ($relation['children'][$action] ?? [] as $child) {
					$this->compileRelationPlans($child['state'], $child['relations']);
				}
			}
		}
	}

	private function relationChildStates(array $children): array
	{
		$states = [
			'create' => [],
			'update' => [],
			'delete' => [],
		];

		foreach (self::CHILD_ACTIONS as $operation) {
			foreach ($children[$operation] ?? [] as $child) {
				$states[$operation][] = $child['state'];
			}
		}

		return $states;
	}

	private function resolveOperation(string $mode, CollectionInterface $collection, ?PrimaryKeyValue $id): string
	{
		if ($mode !== 'upsert') {
			return $mode;
		}

		return $id !== null && $this->findRowByIdentity($collection, $id) !== null ? 'update' : 'create';
	}

	private function assertCreateIdAvailable(string $operation, CollectionInterface $collection, MutationStateInterface $state): void
	{
		if ($operation !== 'create') {
			return;
		}

		$createId = $this->getInputPrimaryKeyValue($collection, $state->getData());
		if (
			$createId !== null
			&& !array_filter($createId->values(), static fn(mixed $value): bool => $value instanceof ValueRef)
			&& $this->findRowByIdentity($collection, $createId) !== null
		) {
			$primaryKey = implode(', ', $collection->getPrimaryKey()->getFieldNames());
			throw new RestApiError(
				"A record with this {$primaryKey} already exists.",
				'DUPLICATE',
				$collection->getPrimaryKey()->getFieldNames()[0] ?? null,
				409
			);
		}
	}

	private function scheduleNodeLifecycle(array $node, bool $after = false): object|null
	{
		return $this->scheduleLifecycleEvent(
			$node['operation'],
			$node['collection'],
			$node['state'],
			$node['path'],
			$after,
			id: $node['operation'] !== 'create'
				? $this->getPrimaryKeyValueFromState($node['state'])?->toUrlId()
				: null
		);
	}

	private function scheduleRelationPayloadLifecycle(array $relation): void
	{
		foreach (self::ACTIONS as $operation) {
			foreach ($relation['payload'][$operation] ?? [] as $index => $target) {
				if (!in_array($operation, ['connect', 'disconnect'], true)) {
					continue;
				}

				$path = [...$relation['path'], $operation, $index];
				$this->scheduleLifecycleEvent(
					$operation,
					$relation['state']->getCollection(),
					$relation['state'],
					$path,
					false,
					$relation['handler']->getRelationName(),
					$relation['handler']->getTargetCollection(),
					$target
				);
				$this->scheduleLifecycleEvent(
					$operation,
					$relation['state']->getCollection(),
					$relation['state'],
					$path,
					true,
					$relation['handler']->getRelationName(),
					$relation['handler']->getTargetCollection(),
					$target
				);
			}
		}
	}

	private function scheduleLifecycleEvent(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $path,
		bool $after = false,
		?string $relationName = null,
		?CollectionInterface $targetCollection = null,
		mixed $target = null,
		?string $id = null
	): object|null {
		if (!$this->dispatchEvents) {
			return null;
		}

		$event = match ($operation) {
			'create' => $after
				? new ItemCreated($collection, $state, $path, $this->rootCollection, $this->rootState)
				: new ItemCreating($collection, $state, $this->queue, $path, $this->rootCollection, $this->rootState),
			'update' => $after
				? new ItemUpdated($collection, $state, $path, $this->rootCollection, $this->rootState)
				: new ItemUpdating($collection, (string) $id, $state, $this->queue, $path, $this->rootCollection, $this->rootState),
			'delete' => $after ? null : new ItemDeleting($collection, (string) $id, $state, $this->queue, $path, $this->rootCollection, $this->rootState),
			'connect' => $after
				? new RelationConnected($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState)
				: new RelationConnecting($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState),
			'disconnect' => $after
				? new RelationDisconnected($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState)
				: new RelationDisconnecting($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState),
			default => null,
		};

		if ($event === null) {
			return null;
		}

		if ($after) {
			$this->afterEvents[] = $event;
			return $event;
		}

		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		return $event;
	}

	private function relationChildPath(array $path, mixed $rawInput, string $operation, int|string $index): array
	{
		if (is_array($rawInput) && $this->isAssociativeArray($rawInput) && array_key_exists($operation, $rawInput)) {
			return [...$path, $operation, $index];
		}

		if ($operation === 'delete') {
			return [...$path, 'delete', $index];
		}

		return [...$path, $index];
	}

	/**
	 * @return array{0: array, 1: array}
	 */
	private function splitNodeInput(CollectionInterface $collection, array $input): array
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

	private function storeDeleteTask(array $node, MutationTaskInterface|MutationDeleteTaskInterface|null $task): void
	{
		if ($node['operation'] !== 'delete' || !$task instanceof MutationDeleteTaskInterface) {
			return;
		}

		$this->afterDeleteEvents[] = [$node['collection'], $node['state'], $task, $node['path']];
	}

	private function lastDeleteTask(): ?MutationDeleteTaskInterface
	{
		if ($this->afterDeleteEvents === []) {
			return null;
		}

		return $this->afterDeleteEvents[array_key_last($this->afterDeleteEvents)][2];
	}

	private function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): ?PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->extractFromInput($input);
	}

	private function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	private function normalizeIdentity(CollectionInterface $collection, PrimaryKeyValue|string $identity): PrimaryKeyValue
	{
		return PrimaryKeyCriteria::normalize($collection, $identity);
	}

	private function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
	}

	private function findRowByIdentity(CollectionInterface $collection, PrimaryKeyValue $identity): ?array
	{
		$fieldNames = [];
		foreach ($collection->fields as $fieldName => $field) {
			if (!$field->isHidden()) {
				$fieldNames[] = (string) $fieldName;
			}
		}

		foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {
			if (!in_array($fieldName, $fieldNames, true)) {
				$fieldNames[] = $fieldName;
			}
		}

		$query = $this->dataSource->select($collection, $fieldNames);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);

		return $this->dataSource->fetchOne($query);
	}

	private function getPrimaryKeyValueFromState(MutationStateInterface $state): ?PrimaryKeyValue
	{
		$values = [];
		foreach ($state->getCollection()->getPrimaryKey()->getFieldNames() as $fieldName) {
			$value = $state->getValue($fieldName);
			if ($value instanceof ValueRef && !$value->isReady()) {
				return null;
			}

			$values[$fieldName] = $state->resolveValue($value);
		}

		return new PrimaryKeyValue($state->getCollection(), $values);
	}

	private function assertAuthorized(object $event): void
	{
		if (!$event instanceof AuthorizationAwareEventInterface) {
			return;
		}

		match ($event->getAuthState()) {
			AuthState::Allowed => null,
			AuthState::Unauthenticated => throw RestApiError::unauthenticated(),
			AuthState::Forbidden, AuthState::Pending => throw RestApiError::forbidden(),
		};
	}
}
