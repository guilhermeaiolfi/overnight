<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Mutation\NodeState;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RelationNode;
use ON\RestApi\Mutation\ValueRef;

trait ManyToManyCompile
{
	use RelationCompileSupport;

	public function reconcileRelation(RecordNode $source, RelationNode $relation): void
	{
		$currentRows = $source->operation === 'create'
			? []
			: $this->getCurrentManyToManyRows($source, $relation);
		$hasExplicitInput = false;
		$hasDesiredInput = false;
		$seenKeys = [];

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted') {
				continue;
			}

			$hasExplicitInput = $hasExplicitInput || $child->relationIntent === 'explicit';
			$hasDesiredInput = $hasDesiredInput || $child->relationIntent === 'desired';
			$child->inputIdentity ??= $this->manyToManyInputIdentity($relation, $child->relationData);
			$child->plannedOperation ??= (is_array($child->relationData) && $child->inputIdentity === null)
				? 'create'
				: 'upsert';

			$matchedRow = null;
			foreach ($this->manyToManyMatchKeysFromInput($relation, $child->relationData, $child->inputIdentity) as $key) {
				$seenKeys[$key] = true;
				foreach ($currentRows as $row) {
					if (in_array($key, $this->manyToManyMatchKeysFromRow($relation, $row), true)) {
						$matchedRow = $row;
						break 2;
					}
				}
			}

			$child->currentData = $matchedRow;
			$child->currentIdentity = $matchedRow === null ? null : $this->manyToManyCurrentIdentity($relation, $matchedRow);
			$this->normalizeManyToManyChildIntent($source, $relation, $child);
		}

		if ($hasDesiredInput && ! $hasExplicitInput) {
			foreach ($currentRows as $row) {
				$currentKeys = $this->manyToManyMatchKeysFromRow($relation, $row);
				if ($currentKeys === [] || $this->hasSeenCurrentManyToManyRow($currentKeys, $seenKeys)) {
					continue;
				}

				$relation->children[] = new RecordNode(
					collection: $relation->targetCollection,
					currentData: $row,
					relationIntent: 'omitted',
					currentIdentity: $this->manyToManyCurrentIdentity($relation, $row),
					plannedOperation: $this->omittedChildMode($relation),
					relationMutation: true,
				);
				$this->normalizeManyToManyChildIntent($source, $relation, $relation->children[array_key_last($relation->children)]);
			}
		}

		foreach ($relation->children as $child) {
			$this->materializeChildNode($source, $relation, $child);
		}

		$this->ensureThroughChildrenForCreatedTargets($source, $relation);

		foreach ($relation->children as $child) {
			$this->materializeChildNode($source, $relation, $child);
		}
	}

	private function normalizeManyToManyChildIntent(RecordNode $source, RelationNode $relation, RecordNode $child): void
	{
		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);
		$throughCollection = $manyToMany->through->getCollection();
		$child->inputIdentity ??= $this->manyToManyInputIdentity($relation, $child->relationData);
		if ($child->currentIdentity === null && $child->currentData !== null) {
			$child->currentIdentity = $this->manyToManyCurrentIdentity($relation, $child->currentData);
		}

		if ($child->relationIntent === 'omitted') {
			if ($child->currentData === null) {
				return;
			}

			$existingPivotId = $throughCollection->getPrimaryKey()->extract($child->currentData);
			$deleteData = $existingPivotId?->getValues() ?? $child->currentData;
			$child->retarget($throughCollection, $deleteData, 'delete', $child->currentData);
			return;
		}

		if ($child->plannedOperation === 'delete') {
			$deleteData = is_array($child->relationData) ? $child->relationData : [];
			if ($deleteData === [] && $child->inputIdentity !== null) {
				$deleteData = $child->inputIdentity->getValues();
			}
			$child->retarget(
				$throughCollection,
				$this->normalizeThroughDeletePayload($manyToMany, $source->state, $deleteData),
				'delete',
				$child->currentData,
			);
			$child->currentIdentity ??= $child->inputIdentity;
			return;
		}

		$input = $child->relationData;
		if (! is_array($input)) {
			if (
				$child->currentData !== null
				&& $child->currentIdentity !== null
				&& $child->inputIdentity !== null
				&& $child->currentIdentity->toUrlId() === $child->inputIdentity->toUrlId()
			) {
				return;
			}

			if ($child->inputIdentity === null) {
				return;
			}

			$child->retarget(
				$throughCollection,
				$this->throughPayloadForTargetIdentity($manyToMany, $source->state, $child->inputIdentity),
				'create',
			);
			return;
		}

		$data = $input;
		if ($this->isThroughPayload($manyToMany, $relation->targetCollection, $input)) {
			$data = $this->normalizeThroughPayload($manyToMany, $source->state, $data);
			if ($child->currentData !== null) {
				$existingPivotId = $throughCollection->getPrimaryKey()->extract($child->currentData);
				if ($existingPivotId !== null) {
					$data += $existingPivotId->getValues();
				}
			}

			$child->retarget(
				$throughCollection,
				$data,
				$child->currentData !== null ? 'upsert' : 'create',
				$child->currentData,
			);
			return;
		}

		if ($child->inputIdentity === null) {
			$child->mergeFields($data);
			$child->retarget($relation->targetCollection, $child->fields, 'create');
			return;
		}

		if ($child->currentData !== null) {
			$child->mergeFields($data);
			$child->retarget($relation->targetCollection, $child->fields, 'upsert', $child->currentData);
			return;
		}

		if (count($data) > count($child->inputIdentity->getValues())) {
			$child->mergeFields($data);
			$child->retarget($relation->targetCollection, $child->fields, 'upsert');
		}
	}

	private function ensureThroughChildrenForCreatedTargets(RecordNode $source, RelationNode $relation): void
	{
		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);
		$throughCollection = $manyToMany->through->getCollection();
		$targetName = $relation->targetCollection->getName();
		$throughName = $throughCollection->getName();
		$existingThroughCreates = [];

		foreach ($relation->children as $child) {
			if ($child->collection->getName() === $throughName && $child->operation === 'create') {
				$existingThroughCreates[$child->relationIndex] = true;
			}
		}

		foreach ($relation->children as $child) {
			if (
				$child->collection->getName() !== $targetName
				|| $child->currentData !== null
				|| ! in_array($child->plannedOperation, ['create', 'upsert'], true)
				|| isset($existingThroughCreates[$child->relationIndex])
			) {
				continue;
			}

			$payload = [];
			foreach ($manyToMany->through->throughInnerKeys() as $index => $throughInnerKey) {
				$payload[$throughInnerKey] = $source->state->getValue($manyToMany->innerKeys()[$index]);
			}
			foreach ($manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
				$payload[$throughOuterKey] = ValueRef::forStateField(
					$child->state,
					$manyToMany->outerKeys()[$index],
				);
			}

			$relation->children[] = new RecordNode(
				collection: $throughCollection,
				fields: $payload,
				operation: 'create',
				state: new NodeState($throughCollection, $payload),
				relationIntent: 'desired',
				relationIndex: $child->relationIndex,
				inputIdentity: $child->inputIdentity,
				plannedOperation: 'create',
				relationMutation: true,
			);
			$existingThroughCreates[$child->relationIndex] = true;
		}
	}

	private function manyToManyInputIdentity(RelationNode $relation, mixed $input): ?PrimaryKeyValue
	{
		$identity = $this->targetIdentityFromInput($relation, $input);
		if ($identity !== null) {
			return $identity;
		}

		if (! is_array($input)) {
			return null;
		}

		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);

		$values = [];
		foreach ($manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
			if (! array_key_exists($throughOuterKey, $input)) {
				return null;
			}
			$values[$manyToMany->outerKeys()[$index]] = $input[$throughOuterKey];
		}

		return new PrimaryKeyValue($relation->targetCollection, $values);
	}

	private function manyToManyCurrentIdentity(RelationNode $relation, array $row): ?PrimaryKeyValue
	{
		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);

		$values = [];
		foreach ($manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
			if (! array_key_exists($throughOuterKey, $row)) {
				return null;
			}
			$values[$manyToMany->outerKeys()[$index]] = $row[$throughOuterKey];
		}

		return new PrimaryKeyValue($relation->targetCollection, $values);
	}

	private function getCurrentManyToManyRows(RecordNode $source, RelationNode $relation): array
	{
		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);

		$fieldValueMap = [];
		foreach ($manyToMany->innerKeys() as $index => $innerKey) {
			$fieldValueMap[$manyToMany->through->throughInnerKeys()[$index]] = $source->state->resolveValue($source->state->getValue($innerKey));
		}

		return $this->fetchRowsByFields($manyToMany->through->getCollection(), $fieldValueMap);
	}

	/**
	 * @return list<string>
	 */
	private function manyToManyMatchKeysFromInput(RelationNode $relation, mixed $input, ?PrimaryKeyValue $identity): array
	{
		$keys = [];
		if ($identity !== null) {
			$keys[] = 'target:' . $identity->toUrlId();
		}

		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);
		if (is_array($input)) {
			$pivotId = $manyToMany->through->getCollection()->getPrimaryKey()->extract($input);
			if ($pivotId !== null) {
				$keys[] = 'pivot:' . $pivotId->toUrlId();
			}
		}

		if ($keys === [] && ! is_array($input) && is_scalar($input)) {
			$keys[] = 'target:' . (string) $input;
		}

		return $keys;
	}

	/**
	 * @return list<string>
	 */
	private function manyToManyMatchKeysFromRow(RelationNode $relation, array $row): array
	{
		$keys = [];
		$currentIdentity = $this->manyToManyCurrentIdentity($relation, $row);
		if ($currentIdentity !== null) {
			$keys[] = 'target:' . $currentIdentity->toUrlId();
		}

		$manyToMany = $relation->definition;
		\assert($manyToMany instanceof M2MRelation);
		$pivotId = $manyToMany->through->getCollection()->getPrimaryKey()->extract($row);
		if ($pivotId !== null) {
			$keys[] = 'pivot:' . $pivotId->toUrlId();
		}

		return $keys;
	}

	private function hasSeenCurrentManyToManyRow(array $currentKeys, array $seenKeys): bool
	{
		foreach ($currentKeys as $key) {
			if (isset($seenKeys[$key])) {
				return true;
			}
		}

		return false;
	}

	private function isThroughPayload(M2MRelation $manyToMany, CollectionInterface $target, array $item): bool
	{
		$through = $manyToMany->through->getCollection();
		foreach (array_keys($item) as $key) {
			if (in_array((string) $key, $manyToMany->through->throughOuterKeys(), true)) {
				return true;
			}
			if ($through->fields->has((string) $key) && ! $target->fields->has((string) $key)) {
				return true;
			}
		}

		return false;
	}

	private function normalizeThroughPayload(M2MRelation $manyToMany, NodeStateInterface $source, array $item): array
	{
		foreach ($manyToMany->through->throughInnerKeys() as $index => $throughInnerKey) {
			$item[$throughInnerKey] = $source->getValue($manyToMany->innerKeys()[$index]);
		}

		return $item;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function normalizeThroughDeletePayload(M2MRelation $manyToMany, NodeStateInterface $source, array $item): array
	{
		$item = $this->normalizeThroughPayload($manyToMany, $source, $item);

		foreach ($manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
			if (array_key_exists($throughOuterKey, $item)) {
				continue;
			}

			$targetKey = $manyToMany->outerKeys()[$index];
			if (array_key_exists($targetKey, $item)) {
				$item[$throughOuterKey] = $item[$targetKey];
			}
		}

		return $item;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function throughPayloadForTargetIdentity(
		M2MRelation $manyToMany,
		NodeStateInterface $source,
		PrimaryKeyValue $targetIdentity,
	): array {
		$payload = [];

		foreach ($manyToMany->through->throughInnerKeys() as $index => $throughInnerKey) {
			$payload[$throughInnerKey] = $source->getValue($manyToMany->innerKeys()[$index]);
		}

		foreach ($manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
			$payload[$throughOuterKey] = $targetIdentity->getValue($manyToMany->outerKeys()[$index]);
		}

		return $payload;
	}
}
