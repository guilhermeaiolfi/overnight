<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use Cycle\ORM\Reference\Promise;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RelationNode;
use ON\RestApi\Support\PrimaryKeyCriteria;

/**
 * Mutates the desired Cycle-backed record graph so each relation property on
 * the working record reflects the compiled relation children.
 */
final class ApplyCycleRecordGraph implements HydrationPassInterface
{
	public function __construct(
		private readonly ?CycleRecordLoader $records = null,
	) {
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('ApplyCycleRecordGraph requires a record node.');
		}

		if ($subject->record === null) {
			return $subject;
		}

		foreach ($subject->relations as $relation) {
			$this->syncRelationGraph($subject, $relation);
		}

		return $subject;
	}

	private function syncRelationGraph(RecordNode $source, RelationNode $relation): void
	{
		if ($relation->definition->getCardinality() === 'single') {
			$source->record->{$relation->relationName} = $this->desiredSingleRelationRecord($source, $relation);

			return;
		}

		$source->record->{$relation->relationName} = $this->desiredManyRelationRecords($source, $relation);
	}

	private function desiredSingleRelationRecord(RecordNode $source, RelationNode $relation): ?object
	{
		$current = $this->currentRelationRecords($source, $relation);
		$desired = $current[0] ?? null;

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' || $child->operation === 'delete') {
				$desired = null;
				continue;
			}

			$record = $this->desiredChildRecord($relation, $child);
			if ($record !== null) {
				$desired = $record;
			}
		}

		return $desired;
	}

	/**
	 * @return list<object>
	 */
	private function desiredManyRelationRecords(RecordNode $source, RelationNode $relation): array
	{
		$records = $this->currentRelationRecords($source, $relation);

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' || $child->operation === 'delete') {
				$target = $child->currentIdentity ?? $child->inputIdentity;
				$this->removeRecordByIdentity($records, $relation->targetCollection, $target);
				continue;
			}

			$record = $this->desiredChildRecord($relation, $child);
			if ($record !== null) {
				$this->upsertRecordByIdentity($records, $relation->targetCollection, $record);
			}
		}

		return array_values($records);
	}

	private function desiredChildRecord(RelationNode $relation, RecordNode $child): ?object
	{
		if ($child->record !== null) {
			if ($child->collection->getName() === $relation->targetCollection->getName()) {
				return $child->record;
			}

			$identity = $child->inputIdentity
				?? $child->currentIdentity
				?? $this->targetIdentityFromNode($relation, $child);

			if ($identity !== null) {
				foreach ($identity->getValues() as $value) {
					if ($value instanceof \ON\RestApi\Mutation\ValueRef && ! $value->isReady()) {
						return null;
					}
				}
			}

			return $identity === null ? null : $this->loadTargetRecord($relation->targetCollection, $identity);
		}

		if ($child->inputIdentity !== null) {
			return $this->loadTargetRecord($relation->targetCollection, $child->inputIdentity);
		}

		return null;
	}

	private function targetIdentityFromNode(RelationNode $relation, RecordNode $node): ?PrimaryKeyValue
	{
		if ($node->collection->getName() === $relation->targetCollection->getName()) {
			return $relation->targetCollection->getPrimaryKey()->extract($node->fields)
				?? ($node->currentData !== null ? $relation->targetCollection->getPrimaryKey()->extract($node->currentData) : null);
		}

		if (! $relation->definition instanceof M2MRelation) {
			return null;
		}

		$values = [];
		foreach ($relation->definition->through->throughOuterKeys() as $index => $throughOuterKey) {
			$value = $node->fields[$throughOuterKey] ?? $node->currentData[$throughOuterKey] ?? null;
			if ($value === null) {
				return null;
			}
			$values[$relation->definition->outerKeys()[$index]] = $value;
		}

		return new PrimaryKeyValue($relation->targetCollection, $values);
	}

	/**
	 * @return list<object>
	 */
	private function currentRelationRecords(RecordNode $source, RelationNode $relation): array
	{
		if ($source->currentRecord === null || ! property_exists($source->currentRecord, $relation->relationName)) {
			return [];
		}

		return $this->normalizeRelationRecords($source->currentRecord->{$relation->relationName});
	}

	/**
	 * @return list<object>
	 */
	private function normalizeRelationRecords(mixed $current): array
	{
		if ($current instanceof Promise) {
			$current = $current->fetch();
		}

		if ($current === null) {
			return [];
		}

		if (is_array($current)) {
			return array_values(array_filter($current, 'is_object'));
		}

		if ($current instanceof \Traversable) {
			return array_values(array_filter(iterator_to_array($current, false), 'is_object'));
		}

		return is_object($current) ? [$current] : [];
	}

	private function loadTargetRecord(CollectionInterface $collection, mixed $target): ?object
	{
		if ($this->records === null) {
			return null;
		}

		$identity = $target instanceof PrimaryKeyValue
			? $target
			: PrimaryKeyCriteria::normalize($collection, $target);

		return $this->records->findSnapshotByIdentity($collection, $identity)['record'] ?? null;
	}

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

	private function removeRecordByIdentity(
		array &$records,
		CollectionInterface $collection,
		PrimaryKeyValue|string|int|null $target,
	): void {
		if ($target === null) {
			return;
		}

		$identity = $target instanceof PrimaryKeyValue
			? $target
			: PrimaryKeyCriteria::normalize($collection, $target);

		$records = array_values(array_filter(
			$records,
			fn (object $record): bool => $this->recordIdentity($collection, $record)?->toUrlId() !== $identity->toUrlId(),
		));
	}

	private function recordIdentity(CollectionInterface $collection, object $record): ?PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->extract(get_object_vars($record));
	}
}
