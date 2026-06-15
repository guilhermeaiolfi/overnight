<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use Cycle\ORM\Reference\Promise;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RelationNode;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait RelationCompileSupport
{
	protected function normalizeSingleRelationChildren(RecordNode $source, RelationNode $relation): void
	{
		$currentRows = $this->primeRelationChildren($source, $relation);
		if (! $this->shouldInferOmittedChildren($relation)) {
			return;
		}

		if (isset($currentRows[0]) && ! $this->hasDesiredMatch($relation)) {
			$relation->children[] = $this->makeOmittedChild($relation, $currentRows[0]);
		}
	}

	protected function normalizeManyRelationChildren(RecordNode $source, RelationNode $relation): void
	{
		$currentRows = $this->primeRelationChildren($source, $relation);
		if (! $this->shouldInferOmittedChildren($relation)) {
			return;
		}

		$seen = [];
		foreach ($relation->children as $child) {
			if ($child->inputIdentity !== null) {
				$seen[$child->inputIdentity->toUrlId()] = true;
			}
		}

		foreach ($currentRows as $row) {
			$currentIdentity = $relation->targetCollection->getPrimaryKey()->extract($row);
			if ($currentIdentity === null || isset($seen[$currentIdentity->toUrlId()])) {
				continue;
			}

			$relation->children[] = $this->makeOmittedChild($relation, $row, $currentIdentity);
		}
	}

	protected function getCurrentRelationRows(RecordNode $source, RelationNode $relation): array
	{
		$currentRows = $this->currentRelationRowsFromGraph($source, $relation);
		if ($currentRows !== null) {
			return $currentRows;
		}

		$fieldValueMap = [];
		foreach ($relation->definition->innerKeys() as $index => $innerKey) {
			$value = $source->state->getValue($innerKey);
			if ($value instanceof ValueRef && ! $value->isReady()) {
				return [];
			}

			$fieldValueMap[$relation->definition->outerKeys()[$index]] = $source->state->resolveValue($value);
		}

		return $this->fetchRowsByFields($relation->targetCollection, $fieldValueMap);
	}

	protected function currentRelationRowsFromGraph(RecordNode $source, RelationNode $relation): ?array
	{
		if ($source->currentData === null || ! array_key_exists($relation->relationName, $source->currentData)) {
			return null;
		}

		$current = $source->currentData[$relation->relationName];
		if ($current === null) {
			return [];
		}

		if (! is_array($current)) {
			return [];
		}

		if (array_is_list($current)) {
			return array_values(array_filter($current, 'is_array'));
		}

		return [$current];
	}

	protected function fetchRowsByFields(CollectionInterface $collection, array $fieldValueMap, ?array $fieldNames = null): array
	{
		foreach ($fieldValueMap as $value) {
			if ($value instanceof ValueRef && ! $value->isReady()) {
				return [];
			}
		}

		if ($this->records !== null) {
			return $this->records->findRowsByFields($collection, $fieldValueMap);
		}

		$fieldNames ??= $collection->getVisibleFields();
		foreach (array_keys($fieldValueMap) as $fieldName) {
			if (! in_array((string) $fieldName, $fieldNames, true)) {
				$fieldNames[] = (string) $fieldName;
			}
		}

		$query = $this->items->select($collection, $fieldNames);
		foreach ($fieldValueMap as $fieldName => $value) {
			$query->where($collection->fields->get((string) $fieldName)->getColumn(), $value);
		}

		return array_map(
			fn(array $row): array => $collection->mapRowFromColumns($row),
			$this->items->fetchAll($query)
		);
	}

	protected function applySourceValuesToTargetInput(RelationInterface $relation, array &$input, NodeStateInterface $source): void
	{
		foreach ($relation->innerKeys() as $index => $innerKey) {
			$input[$relation->outerKeys()[$index]] = $source->getValue($innerKey);
		}
	}

	protected function omittedChildMode(RelationNode $relation): string
	{
		return ($relation->definition->isCascade() || ! $relation->definition->isNullable()) ? 'delete' : 'disconnect';
	}

	protected function materializeChildNode(RecordNode $source, RelationNode $relation, RecordNode $child): void
	{
		if ($child->relationIntent === 'omitted' && $child->plannedOperation === $this->omittedChildMode($relation)) {
			if ($child->operation === 'delete' || $child->currentData === null) {
				return;
			}

			$fields = $relation->targetCollection->getPrimaryKey()->extract($child->currentData)?->getValues() ?? $child->currentData;
			$child->retarget($relation->targetCollection, $fields, 'delete', $child->currentData);
			$child->currentRecord ??= $this->matchCurrentRelationRecord($source, $relation, $child);

			return;
		}

		if ($child->plannedOperation === null) {
			return;
		}

		$mode = $child->plannedOperation === 'upsert'
			? 'pending'
			: $child->plannedOperation;

		if ($mode !== 'pending') {
			$child->setOperation($mode);
		}
		$child->syncState();
		$child->currentRecord ??= $this->matchCurrentRelationRecord($source, $relation, $child);
	}

	protected function targetIdentityFromInput(RelationNode $relation, mixed $input): ?PrimaryKeyValue
	{
		if (! is_array($input)) {
			return is_scalar($input)
				? PrimaryKeyCriteria::normalize($relation->targetCollection, $input)
				: null;
		}

		return $relation->targetCollection->getPrimaryKey()->extract($input);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function primeRelationChildren(RecordNode $source, RelationNode $relation): array
	{
		$currentRows = $source->operation === 'create' || $source->state === null
			? []
			: $this->getCurrentRelationRows($source, $relation);

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted') {
				continue;
			}

			$child->inputIdentity ??= $this->targetIdentityFromInput($relation, $child->relationData);
			$child->plannedOperation ??= (is_array($child->relationData) && $child->inputIdentity === null) ? 'create' : 'upsert';

			if ($child->inputIdentity === null) {
				continue;
			}

			foreach ($currentRows as $row) {
				$currentIdentity = $relation->targetCollection->getPrimaryKey()->extract($row);
				if ($currentIdentity?->toUrlId() !== $child->inputIdentity->toUrlId()) {
					continue;
				}

				$child->currentData = $row;
				$child->currentIdentity = $currentIdentity;
				break;
			}
		}

		return $currentRows;
	}

	protected function shouldInferOmittedChildren(RelationNode $relation): bool
	{
		$hasDesiredInput = false;
		$hasExplicitInput = false;

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted') {
				continue;
			}

			$hasDesiredInput = $hasDesiredInput || $child->relationIntent === 'desired';
			$hasExplicitInput = $hasExplicitInput || $child->relationIntent === 'explicit';
		}

		return $hasDesiredInput && ! $hasExplicitInput;
	}

	protected function hasDesiredMatch(RelationNode $relation): bool
	{
		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'desired' && $child->currentData !== null) {
				return true;
			}
		}

		return false;
	}

	protected function makeOmittedChild(
		RelationNode $relation,
		array $row,
		?PrimaryKeyValue $identity = null,
	): RecordNode {
		return new RecordNode(
			collection: $relation->targetCollection,
			currentData: $row,
			relationIntent: 'omitted',
			currentIdentity: $identity ?? $relation->targetCollection->getPrimaryKey()->extract($row),
			plannedOperation: $this->omittedChildMode($relation),
			relationMutation: true,
		);
	}

	private function matchCurrentRelationRecord(
		RecordNode $source,
		RelationNode $relation,
		RecordNode $child,
	): ?object {
		if ($source->currentRecord === null || ! property_exists($source->currentRecord, $relation->relationName)) {
			return null;
		}

		$current = $source->currentRecord->{$relation->relationName};
		$records = $this->normalizeCurrentRelationRecords($current);
		foreach ($records as $record) {
			if (! is_object($record)) {
				continue;
			}

			$identity = $relation->targetCollection->getPrimaryKey()->extract(get_object_vars($record));
			if ($identity === null) {
				continue;
			}

			if ($child->currentIdentity !== null && $identity->toUrlId() === $child->currentIdentity->toUrlId()) {
				return $record;
			}

			if ($child->inputIdentity !== null && $identity->toUrlId() === $child->inputIdentity->toUrlId()) {
				return $record;
			}
		}

		return count($records) === 1 && is_object($records[0]) ? $records[0] : null;
	}

	/**
	 * @return list<mixed>
	 */
	private function normalizeCurrentRelationRecords(mixed $current): array
	{
		if ($current instanceof Promise) {
			$current = $current->fetch();
		}

		if ($current === null) {
			return [];
		}

		if (is_array($current)) {
			return $current;
		}

		if ($current instanceof \Traversable) {
			return iterator_to_array($current, false);
		}

		return is_object($current) ? [$current] : [];
	}
}
