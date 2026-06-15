<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Select as CycleSelect;
use Cycle\ORM\Service\EntityFactoryInterface;
use Cycle\ORM\EntityManager;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\BelongsToRelation;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Event\RelationConnected;
use ON\RestApi\Event\RelationConnecting;
use ON\RestApi\Event\RelationDisconnected;
use ON\RestApi\Event\RelationDisconnecting;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class CycleRecordCommitter
{
	public function __construct(
		private readonly ItemRepositoryInterface $items,
		private readonly CycleRecordLoader $records,
		private readonly RestHookDispatcher $dispatcher,
	) {
	}

	public function commit(RecordStore $store, bool $dispatchEvents = true): ?array
	{
		$queue = new OperationQueue();
		$afterHooksTx = $this->dispatcher->start();
		$inheritNestedAuthorization = false;
		$root = $store->root;

		if ($dispatchEvents) {
			$this->dispatchBeforeHooks($root, $root->state, $queue, $afterHooksTx, $inheritNestedAuthorization);
		}

		$orm = $this->records->orm()->withHeap(new Heap());
		$rootRecord = $this->materializeNode($orm, $root);
		$independentRecords = $this->materializeIndependentRelationRecords($orm, $root);
		if ($rootRecord === null && $root->operation !== 'create') {
			$afterHooksTx->rollback();

			return null;
		}

		$entityManager = new EntityManager($orm);
		$deleted = [];

		if ($root->operation !== 'delete' && $rootRecord !== null) {
			$entityManager->persist($rootRecord, true);
		}
		foreach ($independentRecords as $record) {
			$entityManager->persist($record, true);
		}

		$this->collectDeletedRecords($root, $deleted);
		foreach ($deleted as $record) {
			$entityManager->delete($record, true);
		}

		try {
			$result = $this->items->getDatabase()->transaction(function () use ($entityManager, $queue, $root): ?array {
				$entityManager->run();
				$queue->execute($this->items);
				$this->refreshStatesFromRecords($root);
				$this->applyDeferredRelationCommands($queue, $root);
				$queue->execute($this->items);

				return $root->state->getRow();
			});
			$afterHooksTx->flush();
		} catch (\Throwable $throwable) {
			$afterHooksTx->rollback();
			throw $throwable;
		}

		return $result;
	}

	private function dispatchBeforeHooks(
		RecordNode $node,
		NodeStateInterface $rootState,
		OperationQueue $queue,
		RestHookTransaction $afterHooksTx,
		bool &$inheritNestedAuthorization,
	): void {
		$this->dispatchBeforeNodeHook($node, $rootState, $queue, $inheritNestedAuthorization);

		foreach ($node->relations as $relation) {
			foreach (['create', 'update', 'delete'] as $operation) {
				foreach ($relation->childRecordsByOperation($operation) as $child) {
					$this->dispatchBeforeHooks($child, $rootState, $queue, $afterHooksTx, $inheritNestedAuthorization);
				}
			}

			$this->dispatchBeforeRelationHooks($relation, $rootState, $queue);
			$this->scheduleAfterRelationHooks($afterHooksTx, $relation, $rootState);
		}

		$this->scheduleAfterNodeHook($afterHooksTx, $node, $rootState);
	}

	private function dispatchBeforeNodeHook(
		RecordNode $node,
		NodeStateInterface $rootState,
		OperationQueue $queue,
		bool &$inheritNestedAuthorization,
	): void {
		$id = $node->operation !== 'create'
			? $node->state->getPrimaryKeyValue()
			: null;
		$event = match ($node->operation) {
			'create' => new ItemCreating($node, $queue, $node->path, $rootState),
			'update' => $id === null ? null : new ItemUpdating($node, $id, $queue, $node->path, $rootState),
			default => null,
		};
		if ($event === null) {
			return;
		}

		$inherit = $inheritNestedAuthorization && $event->getPath() !== [];
		$this->dispatchMutationHook($event, $inherit);

		if ($event->getPath() === [] && $event->shouldInheritAuthToNested()) {
			$inheritNestedAuthorization = true;
		}
	}

	private function dispatchBeforeRelationHooks(
		RelationNode $relation,
		NodeStateInterface $rootState,
		OperationQueue $queue,
	): void {
		foreach ($this->connectTargets($relation) as $target) {
			$this->dispatchMutationHook(
				new RelationConnecting($relation, $target, $relation->path, $rootState, $queue),
				false,
				false
			);
		}

		foreach ($this->disconnectTargets($relation) as $target) {
			$this->dispatchMutationHook(
				new RelationDisconnecting($relation, $target, $relation->path, $rootState, $queue),
				false,
				false
			);
		}
	}

	private function dispatchMutationHook(
		object $event,
		bool $inheritNestedAuthorization = false,
		bool $assertAuthorization = true,
	): object {
		if ($inheritNestedAuthorization && $event instanceof AuthorizationAwareEventInterface) {
			$event->inheritNestedAuthorization();
			if ($event->getAuthState() === AuthState::Pending) {
				$event->allow();
			}
		}

		return $this->dispatcher->dispatch($event, $assertAuthorization);
	}

	private function scheduleAfterRelationHooks(
		RestHookTransaction $afterHooksTx,
		RelationNode $relation,
		NodeStateInterface $rootState,
	): void {
		foreach ($this->connectTargets($relation) as $target) {
			$afterHooksTx->schedule(new RelationConnected($relation, $target, $relation->path, $rootState));
		}

		foreach ($this->disconnectTargets($relation) as $target) {
			$afterHooksTx->schedule(new RelationDisconnected($relation, $target, $relation->path, $rootState));
		}
	}

	private function scheduleAfterNodeHook(
		RestHookTransaction $afterHooksTx,
		RecordNode $node,
		NodeStateInterface $rootState,
	): void {
		$afterHooksTx->schedule(static function () use ($node, $rootState): object|null {
			if ($node->state->getRow() === null) {
				return null;
			}

			return match ($node->operation) {
				'create' => new ItemCreated($node->collection, $node->state, $node->path, $rootState),
				'update' => new ItemUpdated($node->collection, $node->state, $node->path, $rootState),
				default => null,
			};
		});
	}

	private function materializeNode(ORMInterface $orm, RecordNode $node): ?object
	{
		$record = match ($node->operation) {
			'create' => $this->makeNewRecord($orm, $node),
			'update', 'delete' => $this->loadManagedRecord($orm, $node),
			default => null,
		};

		if ($record === null) {
			return null;
		}

		foreach ($node->state->getData() as $field => $value) {
			if (! $node->collection->fields->has((string) $field)) {
				continue;
			}

			if ($value instanceof ValueRef) {
				if (! $value->isReady()) {
					continue;
				}

				$value = $value->resolve();
			}

			$record->{$field} = $value;
		}

		foreach ($node->relations as $relation) {
			if ($relation->definition instanceof M2MRelation) {
				continue;
			}

			$record->{$relation->relationName} = $relation->definition->getCardinality() === 'single'
				? $this->materializeSingleRelation($orm, $record, $relation)
				: $this->materializeManyRelation($orm, $record, $relation);
		}

		if ($node->operation === 'delete') {
			$identity = $node->collection->getPrimaryKey()->extract(get_object_vars($record));
			if ($identity !== null) {
				$row = $this->items->findByIdentity($node->collection, $identity);
				if ($row !== null) {
					$node->currentData ??= $row;
					$node->state->markReady($row);
				}
			}
		}

		$node->record = $record;

		return $record;
	}

	/**
	 * @return list<object>
	 */
	private function materializeIndependentRelationRecords(ORMInterface $orm, RecordNode $node): array
	{
		$records = [];

		foreach ($node->relations as $relation) {
			foreach (['create', 'update', 'delete'] as $operation) {
				foreach ($relation->childRecordsByOperation($operation) as $child) {
					if (
						$relation->definition instanceof M2MRelation
						&& $child->collection->getName() !== $relation->targetCollection->getName()
					) {
						array_push($records, ...$this->materializeIndependentRelationRecords($orm, $child));
						continue;
					}

					if ($relation->definition instanceof M2MRelation || $child->collection->getName() !== $relation->targetCollection->getName()) {
						$record = $this->materializeNode($orm, $child);
						if ($record !== null && $child->operation !== 'delete') {
							$records[] = $record;
						}
					}

					array_push($records, ...$this->materializeIndependentRelationRecords($orm, $child));
				}
			}
		}

		return $this->uniqueRecords($records);
	}

	/**
	 * @param array<string, object> $deleted
	 */
	private function collectDeletedRecords(RecordNode $node, array &$deleted): void
	{
		if ($node->operation === 'delete') {
			$record = $node->currentRecord ?? $node->record;
			if (is_object($record)) {
				$deleted[$this->deletionKey($node->collection, $record)] = $record;
			}
		}

		foreach ($node->relations as $relation) {
			foreach ($relation->childRecordsByOperation('delete') as $child) {
				if (
					$relation->definition instanceof M2MRelation
					&& $child->collection->getName() !== $relation->targetCollection->getName()
				) {
					continue;
				}

				$this->collectDeletedRecords($child, $deleted);
			}
		}
	}

	private function deletionKey(CollectionInterface $collection, object $record): string
	{
		$id = $collection->getPrimaryKey()->extract(get_object_vars($record));

		return $collection->getName() . ':' . ($id?->toUrlId() ?? spl_object_id($record));
	}

	private function refreshStatesFromRecords(RecordNode $node): void
	{
		$this->refreshNodeState($node);

		foreach ($node->relations as $relation) {
			foreach (['create', 'update', 'delete'] as $operation) {
				foreach ($relation->childRecordsByOperation($operation) as $child) {
					$this->refreshStatesFromRecords($child);
				}
			}
		}
	}

	private function applyDeferredRelationCommands(OperationQueue $queue, RecordNode $node): void
	{
		foreach ($node->relations as $relation) {
			foreach (['create', 'update', 'delete'] as $operation) {
				foreach ($relation->childRecordsByOperation($operation) as $child) {
					$this->applyDeferredRelationCommands($queue, $child);
				}
			}

			if ($relation->handler !== null && $relation->state !== null) {
				$relation->handler->applyRelation($queue, $relation->state, $relation);
			}
		}
	}

	private function refreshNodeState(RecordNode $node): void
	{
		if ($node->operation === 'delete') {
			if ($node->currentData !== null) {
				$node->state->markReady($node->currentData);
			}

			return;
		}

		$record = $node->record;
		if (! is_object($record)) {
			return;
		}

		$identity = $node->collection->getPrimaryKey()->extract(get_object_vars($record));
		if ($identity === null) {
			return;
		}

		$row = $this->items->findByIdentity($node->collection, $identity);
		if ($row !== null) {
			$node->state->markReady($row);
		}
	}

	private function makeNewRecord(ORMInterface $orm, RecordNode $node): object
	{
		return $orm
			->getService(EntityFactoryInterface::class)
			->make($node->collection->getName(), $this->scalarData($node), Node::NEW, typecast: false);
	}

	private function loadManagedRecord(ORMInterface $orm, RecordNode $node): ?object
	{
		$identity = $node->state->getPrimaryKeyValue(false);
		if ($identity === null) {
			return null;
		}

		$values = array_values($node->collection->getPrimaryKey()->getValue($identity)->getValues());

		$select = new CycleSelect($orm, $node->collection->getName());
		foreach (array_keys($node->relations) as $relationName) {
			$definition = $node->relations[$relationName]->definition;
			if ($definition instanceof M2MRelation) {
				continue;
			}

			$select->load((string) $relationName);
		}

		return $select->wherePK(...$values)->fetchOne();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function scalarData(RecordNode $node): array
	{
		$data = [];
		foreach ($node->state->getData() as $field => $value) {
			if (! $node->collection->fields->has((string) $field)) {
				continue;
			}

			if ($value instanceof ValueRef) {
				if (! $value->isReady()) {
					continue;
				}

				$value = $value->resolve();
			}

			$data[$field] = $value;
		}

		return $data;
	}

	private function materializeSingleRelation(
		ORMInterface $orm,
		object $parentRecord,
		RelationNode $relation,
	): ?object {
		$desired = $this->currentSingleRelationRecord($parentRecord, $relation->relationName);

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' || $child->operation === 'delete') {
				$desired = null;
				continue;
			}

			if (in_array($child->operation, ['create', 'update', 'delete'], true)) {
				$desired = $this->materializeNode($orm, $child);
				$this->bindInverseRelation($relation, $desired, $parentRecord);
				continue;
			}

			if ($child->inputIdentity !== null) {
				$desired = $this->loadManagedTargetRecord($orm, $relation->targetCollection, $child->inputIdentity);
			}
		}

		return $desired;
	}

	/**
	 * @return list<object>
	 */
	private function materializeManyRelation(
		ORMInterface $orm,
		object $parentRecord,
		RelationNode $relation,
	): array {
		$records = $this->normalizeRelationRecords($parentRecord->{$relation->relationName} ?? []);

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' || $child->operation === 'delete') {
				$this->removeRecordByIdentity($records, $relation->targetCollection, $child->currentIdentity ?? $child->inputIdentity);
				continue;
			}

			if (in_array($child->operation, ['create', 'update', 'delete'], true)) {
				$record = $this->materializeNode($orm, $child);
				if ($record !== null) {
					$this->bindInverseRelation($relation, $record, $parentRecord);
					$this->upsertRecordByIdentity($records, $relation->targetCollection, $record);
				}
				continue;
			}

			if ($child->inputIdentity !== null) {
				$record = $this->loadManagedTargetRecord($orm, $relation->targetCollection, $child->inputIdentity);
				if ($record !== null) {
					$this->upsertRecordByIdentity($records, $relation->targetCollection, $record);
				}
			}
		}

		return array_values($records);
	}

	private function currentSingleRelationRecord(object $record, string $relationName): ?object
	{
		if (! property_exists($record, $relationName)) {
			return null;
		}

		$current = $record->{$relationName};
		if ($current instanceof \Cycle\ORM\Reference\Promise) {
			$current = $current->fetch();
		}

		return is_object($current) ? $current : null;
	}

	private function loadManagedTargetRecord(
		ORMInterface $orm,
		CollectionInterface $collection,
		PrimaryKeyValue|int|string $target,
	): ?object {
		$identity = $target instanceof PrimaryKeyValue
			? $target
			: $collection->getPrimaryKey()->getValue($target);

		return (new CycleSelect($orm, $collection->getName()))
			->wherePK(...array_values($identity->getValues()))
			->fetchOne();
	}

	/**
	 * @return list<object>
	 */
	private function normalizeRelationRecords(mixed $value): array
	{
		if ($value instanceof \Cycle\ORM\Reference\Promise) {
			$value = $value->fetch();
		}

		if ($value === null) {
			return [];
		}

		if (is_array($value)) {
			return array_values(array_filter($value, 'is_object'));
		}

		if ($value instanceof \Traversable) {
			return array_values(array_filter(iterator_to_array($value, false), 'is_object'));
		}

		return is_object($value) ? [$value] : [];
	}

	private function recordIdentity(CollectionInterface $collection, object $record): ?PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->extract(get_object_vars($record));
	}

	/**
	 * @param array<int, object> $records
	 */
	private function upsertRecordByIdentity(array &$records, CollectionInterface $collection, object $record): void
	{
		$identity = $this->recordIdentity($collection, $record);
		if ($identity === null) {
			$records[] = $record;

			return;
		}

		foreach ($records as $index => $existing) {
			$existingIdentity = $this->recordIdentity($collection, $existing);
			if ($existingIdentity !== null && $existingIdentity->toUrlId() === $identity->toUrlId()) {
				$records[$index] = $record;

				return;
			}
		}

		$records[] = $record;
	}

	/**
	 * @param array<int, object> $records
	 */
	private function removeRecordByIdentity(
		array &$records,
		CollectionInterface $collection,
		PrimaryKeyValue|int|string|null $target,
	): void {
		if ($target === null) {
			return;
		}

		$identity = $target instanceof PrimaryKeyValue
			? $target
			: $collection->getPrimaryKey()->getValue($target);

		$records = array_values(array_filter(
			$records,
			fn (object $record): bool => $this->recordIdentity($collection, $record)?->toUrlId() !== $identity->toUrlId(),
		));
	}

	private function bindInverseRelation(RelationNode $relation, ?object $childRecord, object $parentRecord): void
	{
		if ($childRecord === null || $relation->definition instanceof BelongsToRelation) {
			return;
		}

		$inverse = $this->inferInverseRelationName($relation);
		if ($inverse === null) {
			return;
		}

		$childRecord->{$inverse} = $parentRecord;
	}

	private function inferInverseRelationName(RelationNode $relation): ?string
	{
		$sourceCollection = $relation->state?->getCollection();
		if ($sourceCollection === null) {
			return null;
		}

		foreach ($relation->targetCollection->relations as $candidate) {
			if (
				$candidate instanceof M2MRelation
				|| $candidate->getCollectionName() !== $sourceCollection->getName()
				|| $candidate->innerKeys() !== $relation->definition->outerKeys()
				|| $candidate->outerKeys() !== $relation->definition->innerKeys()
			) {
				continue;
			}

			return $candidate->getName();
		}

		return null;
	}

	/**
	 * @param list<object> $records
	 * @return list<object>
	 */
	private function uniqueRecords(array $records): array
	{
		$unique = [];
		foreach ($records as $record) {
			$unique[spl_object_id($record)] = $record;
		}

		return array_values($unique);
	}

	private function connectTargets(RelationNode $relation): array
	{
		$targets = [];
		foreach ($relation->children as $child) {
			if (
				$child->relationIntent === 'desired'
				&& ! in_array($child->operation, ['create', 'update', 'delete'], true)
				&& $child->inputIdentity !== null
				&& ($child->currentIdentity === null || $child->currentIdentity->toUrlId() !== $child->inputIdentity->toUrlId())
			) {
				$targets[$child->inputIdentity->toUrlId()] = $child->inputIdentity;
			}
		}

		return array_values($targets);
	}

	private function disconnectTargets(RelationNode $relation): array
	{
		$targets = [];
		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' && $child->currentIdentity !== null && ! in_array($child->operation, ['create', 'update', 'delete'], true)) {
				$targets[$child->currentIdentity->toUrlId()] = $child->currentIdentity;
			}
		}

		return array_values($targets);
	}
}
