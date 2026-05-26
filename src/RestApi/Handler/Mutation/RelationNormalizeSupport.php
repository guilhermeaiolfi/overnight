<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait RelationNormalizeSupport
{
	use RelationStateSupport;

	protected function getCurrentRelationRows(MutationStateInterface $source): array
	{
		$fieldValueMap = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$value = $source->getValue($innerKey);
			if ($value instanceof ValueRef && !$value->isReady()) {
				return [];
			}

			$fieldValueMap[$this->relation->outerKeys()[$index]] = $source->resolveValue($value);
		}

		return $this->fetchRowsByFields($this->getTargetCollection(), $fieldValueMap);
	}

	protected function getCurrentParentRow(MutationStateInterface $source): ?array
	{
		$identity = $this->getPrimaryKeyValueFromState($source, false);
		if ($identity === null) {
			return null;
		}

		return $this->fetchRowByIdentity($source->getCollection(), $identity);
	}

	protected function fetchRowsByField(
		CollectionInterface $collection,
		string $fieldName,
		mixed $value,
		?array $fieldNames = null
	): array {
		$fieldNames ??= $collection->getVisibleFields();
		if (!in_array($fieldName, $fieldNames, true)) {
			$fieldNames[] = $fieldName;
		}

		$query = $this->items->select($collection, $fieldNames);
		$query->where($collection->fields->get($fieldName)->getColumn(), $value);

		return array_map(
			fn(array $row): array => $collection->mapRowFromColumns($row),
			$this->items->fetchAll($query)
		);
	}

	protected function fetchRowsByFields(
		CollectionInterface $collection,
		array $fieldValueMap,
		?array $fieldNames = null
	): array {
		$fieldNames ??= $collection->getVisibleFields();
		foreach (array_keys($fieldValueMap) as $fieldName) {
			if (!in_array((string) $fieldName, $fieldNames, true)) {
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

	protected function fetchRowByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity
	): ?array {
		$fieldNames = $collection->getVisibleFields();
		foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {
			if (!in_array($fieldName, $fieldNames, true)) {
				$fieldNames[] = $fieldName;
			}
		}

		$query = $this->items->select($collection, $fieldNames);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->items->fetchOne($query);

		return $row === null ? null : $collection->mapRowFromColumns($row);
	}

	protected function applySourceValuesToTargetInput(array &$input, MutationStateInterface $source): void
	{
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$input[$this->relation->outerKeys()[$index]] = $source->getValue($innerKey);
		}
	}

	protected function getTargetIdentityFromSourceRow(array $row): ?PrimaryKeyValue
	{
		$values = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			if (!array_key_exists($innerKey, $row)) {
				return null;
			}

			$values[$this->relation->outerKeys()[$index]] = $row[$innerKey];
		}

		return new PrimaryKeyValue($this->getTargetCollection(), $values);
	}
}
