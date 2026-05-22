<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
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
use ON\RestApi\Resolver\DataSourceInterface;
use ON\RestApi\Resolver\Sql\Loader\LoaderFactory;
use ON\RestApi\Resolver\Sql\Loader\RelationLoaderInterface;
use ON\RestApi\Resolver\Sql\Loader\RootLoader;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestMutationPlanner
{
	private const RELATION_ACTIONS = ['create', 'update', 'delete', 'connect', 'disconnect'];
	private const CHILD_MUTATION_ACTIONS = ['create', 'update', 'delete'];

	/** @var list<object> */
	private array $afterEvents = [];

	/** @var list<array{0: CollectionInterface, 1: MutationStateInterface, 2: MutationDeleteTaskInterface, 3: array}> */
	private array $afterDeleteEvents = [];

	public function __construct(
		private DataSourceInterface $dataSource,
		private LoaderFactory $relationHandlers,
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
		?string $id = null,
		array $path = []
	): ?MutationTaskInterface {
		$prevented = null;
		$node = $this->planSaveNode($mode, $collection, $input, $id, $path, $prevented);
		if ($node === null) {
			return $prevented;
		}

		return $this->compileNode($node);
	}

	public function delete(CollectionInterface $collection, string $id, array $path = []): ?MutationDeleteTaskInterface
	{
		$prevented = null;
		$node = $this->planDeleteNode($collection, $id, $path, $prevented);
		if ($node === null) {
			return $prevented;
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
		CollectionInterface $collection,
		array|MutationStateInterface $input,
		?string $id,
		array $path,
		?MutationTaskInterface &$prevented = null
	): ?array {
		$state = $input instanceof MutationStateInterface
			? $input
			: ($path === [] && $collection === $this->rootCollection
				? $this->rootState
				: new MutationState($collection, $input));
		if (!$input instanceof MutationStateInterface && $state === $this->rootState) {
			$state->setData($input);
		}

		if ($id === null && $mode !== 'create') {
			$id = $this->inputPrimaryKeyValue($collection, $state->getData());
		}
		$id = $id === null ? null : (string) $id;
		$operation = $mode;

		if ($mode === 'upsert') {
			$operation = $id !== null && $this->dataSource->get($collection, $id) !== null
				? 'update'
				: 'create';
		}

		if ($operation === 'update' && $id === null) {
			return null;
		}
		if ($operation === 'update') {
			$state->setValue($this->getPrimaryKeyName($collection), $id);
		}

		$beforeEvent = $this->scheduleLifecycleEvents($operation, $collection, $state, $path, id: $id);
		if ($beforeEvent instanceof ItemCreating && $beforeEvent->isDefaultPrevented()) {
			$prevented = new MutationTask(MutationState::fromRow($collection, $beforeEvent->getPreventedResult()));
			return null;
		}

		if ($operation === 'create') {
			$createId = $this->inputPrimaryKeyValue($collection, $state->getData());
			if (
				$createId !== null
				&& !$createId instanceof ValueRef
				&& $this->dataSource->get($collection, (string) $createId) !== null
			) {
				throw new RestApiError(
					"A record with this {$this->getPrimaryKeyName($collection)} already exists.",
					'DUPLICATE',
					$this->getPrimaryKeyName($collection),
					409
				);
			}
		}

		[$scalarInput, $relationInput] = $this->splitNodeInput($collection, $state->getData());
		$state->setData($scalarInput);
		$relations = $this->planRelations($operation, $collection, $relationInput, $state, $path);

		$this->scheduleAfterSaveEvent($operation, $collection, $state, $path);

		return [
			'operation' => $operation,
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'relations' => $relations,
		];
	}

	private function planDeleteNode(
		CollectionInterface $collection,
		string $id,
		array $path,
		?MutationDeleteTaskInterface &$prevented = null
	): ?array {
		$state = new MutationState($collection, [$this->getPrimaryKeyName($collection) => $id]);

		$beforeEvent = $this->scheduleLifecycleEvents('delete', $collection, $state, $path, id: $id);
		if ($beforeEvent instanceof ItemDeleting && $beforeEvent->isDefaultPrevented()) {
			$prevented = new MutationDeleteTask(static fn(): bool => $beforeEvent->getPreventedResult());
			return null;
		}

		return [
			'operation' => 'delete',
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'relations' => [],
		];
	}

	private function planRelation(
		string $operation,
		CollectionInterface $collection,
		string $relationName,
		mixed $relationInput,
		MutationStateInterface $state,
		array $path
	): ?array {
		$handler = $this->relationHandlers->mutation($collection, $relationName);
		if ($handler === null) {
			return null;
		}

		$payload = $handler->normalizePayload($operation, $relationInput, $state, $this->dataSource);
		$children = $this->planRelationActions($handler, $payload, $relationInput, $path);
		$this->scheduleRelationEvents($collection, $relationName, $handler->getTargetCollection(), $state, $path, $payload);

		return [
			'handler' => $handler,
			'payload' => $payload,
			'children' => $children,
		];
	}

	private function planRelations(
		string $operation,
		CollectionInterface $collection,
		array $relationInput,
		MutationStateInterface $state,
		array $path
	): array {
		$relations = [];
		foreach ($relationInput as $relationName => $input) {
			$relation = $this->planRelation($operation, $collection, $relationName, $input, $state, [...$path, $relationName]);
			if ($relation !== null) {
				$relations[$relationName] = $relation;
			}
		}

		return $relations;
	}

	private function planRelationActions(
		RelationLoaderInterface $handler,
		array $payload,
		mixed $rawInput,
		array $path
	): array {
		$children = [
			'create' => [],
			'update' => [],
			'delete' => [],
		];

		foreach (self::CHILD_MUTATION_ACTIONS as $action) {
			foreach ($payload[$action] ?? [] as $index => $item) {
				$child = $this->planRelationAction($handler, $action, $item, $rawInput, $path, $index);
				if ($child !== null) {
					$children[$child['operation']][] = $child;
				}
			}
		}

		return $children;
	}

	private function planRelationAction(
		RelationLoaderInterface $handler,
		string $action,
		mixed $item,
		mixed $rawInput,
		array $path,
		int|string $index
	): ?array {
		if ($action !== 'delete' && !is_array($item)) {
			return null;
		}

		$childCollection = $handler->mutationCollection($action, $item);
		$childPath = $this->relationChildPath($path, $rawInput, $action, $index);

		return match ($action) {
			'create' => $this->planSaveNode('create', $childCollection, $item, null, $childPath),
			'update' => $this->planSaveNode(
				'upsert',
				$childCollection,
				$item,
				($id = $this->inputPrimaryKeyValue($childCollection, $item)) === null ? null : (string) $id,
				$childPath
			),
			'delete' => $this->planDeleteActionNode($childCollection, $item, $childPath),
			default => null,
		};
	}

	private function planDeleteActionNode(CollectionInterface $collection, mixed $item, array $path): ?array
	{
		$id = is_array($item) ? $this->inputPrimaryKeyValue($collection, $item) : $item;
		if ($id === null) {
			return null;
		}

		return $this->planDeleteNode($collection, (string) $id, $path);
	}

	private function scheduleAfterSaveEvent(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $path
	): void {
		$this->scheduleLifecycleEvents($operation, $collection, $state, $path, after: true);
	}

	private function scheduleLifecycleEvents(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $path,
		?string $relationName = null,
		?CollectionInterface $targetCollection = null,
		mixed $target = null,
		bool $after = false,
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

	private function scheduleRelationEvents(
		CollectionInterface $collection,
		string $relationName,
		CollectionInterface $targetCollection,
		MutationStateInterface $state,
		array $path,
		array $payload
	): void {
		foreach (self::RELATION_ACTIONS as $operation) {
			if (!in_array($operation, ['connect', 'disconnect'], true)) {
				continue;
			}

			foreach ($payload[$operation] ?? [] as $index => $target) {
				$targetPath = [...$path, $operation, $index];
				$this->scheduleLifecycleEvents($operation, $collection, $state, $targetPath, $relationName, $targetCollection, $target, false);
				$this->scheduleLifecycleEvents($operation, $collection, $state, $targetPath, $relationName, $targetCollection, $target, true);
			}
		}
	}

	private function compileNode(array $node): MutationTaskInterface|MutationDeleteTaskInterface|null
	{
		$task = RootLoader::compileRootAction($node, $this->queue);
		$this->storeDeleteTask($node, $task);
		$this->compileRelationPlans($node);

		return $task;
	}

	private function compileRelationPlans(array $node): void
	{
		foreach ($node['relations'] ?? [] as $relation) {
			$relation['handler']->{$node['operation']}(
				$relation['payload'],
				$node['state'],
				$this->relationChildStates($relation['children']),
				$this->queue
			);

			foreach (self::CHILD_MUTATION_ACTIONS as $action) {
				foreach ($relation['children'][$action] ?? [] as $child) {
					$this->compileRelationPlans($child);
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

		foreach (self::CHILD_MUTATION_ACTIONS as $operation) {
			foreach ($children[$operation] ?? [] as $child) {
				$states[$operation][] = $child['state'];
			}
		}

		return $states;
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

		$last = $this->afterDeleteEvents[array_key_last($this->afterDeleteEvents)];

		return $last[2];
	}

	private function relationChildPath(array $path, mixed $rawInput, string $operation, int|string $index): array
	{
		if (is_array($rawInput) && $this->isAssociativeArray($rawInput) && array_key_exists($operation, $rawInput)) {
			return [...$path, $operation, $index];
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

	private function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKey = $this->getPrimaryKeyName($collection);

		return array_key_exists($primaryKey, $input) ? $input[$primaryKey] : null;
	}

	private function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	private function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getName();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getName();
		}

		return 'id';
	}

	private function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
	}

	private function assertAuthorized(object $event): void
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
}
