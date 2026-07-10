<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyCriteria;
use ON\RestApi\Support\PrimaryKeyValue;

trait RelationNormalizeSupport
{
	use RelationStateSupport;

	protected function getCurrentRelationRows(MutationStateInterface $source): array
	{
		$fieldValueMap = [];
		foreach ($this->relation->getInnerKeys() as $index => $innerKey) {
			$value = $source->getValue($innerKey);
			if ($value instanceof ValueRef && ! $value->isReady()) {
				return [];
			}

			$fieldValueMap[$this->relation->getOuterKeys()[$index]] = $source->resolveValue($value);
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
		if (! in_array($fieldName, $fieldNames, true)) {
			$fieldNames[] = $fieldName;
		}

		$query = $this->items->select($collection, $fieldNames);
		$query->where(\ON\Data\Query\x()->eq($query->field($fieldName), $value));

		return $this->items->fetchAll($query);
	}

	protected function fetchRowsByFields(
		CollectionInterface $collection,
		array $fieldValueMap,
		?array $fieldNames = null
	): array {
		$fieldNames ??= $collection->getVisibleFields();
		foreach (array_keys($fieldValueMap) as $fieldName) {
			if (! in_array((string) $fieldName, $fieldNames, true)) {
				$fieldNames[] = (string) $fieldName;
			}
		}

		$query = $this->items->select($collection, $fieldNames);
		foreach ($fieldValueMap as $fieldName => $value) {
			$query->where(\ON\Data\Query\x()->eq($query->field((string) $fieldName), $value));
		}

		return $this->items->fetchAll($query);
	}

	protected function fetchRowByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity
	): ?array {
		$fieldNames = $collection->getVisibleFields();
		foreach (PrimaryKey::of($collection)->getFieldNames() as $fieldName) {
			if (! in_array($fieldName, $fieldNames, true)) {
				$fieldNames[] = $fieldName;
			}
		}

		$query = $this->items->select($collection, $fieldNames);
		foreach (PrimaryKeyCriteria::build($collection, $identity) as $fieldName => $value) {
			$query->where(\ON\Data\Query\x()->eq($query->field($fieldName), $value));
		}
		$query->limit(1);

		return $this->items->fetchOne($query);
	}

	protected function applySourceValuesToTargetInput(array &$input, MutationStateInterface $source): void
	{
		foreach ($this->relation->getInnerKeys() as $index => $innerKey) {
			$input[$this->relation->getOuterKeys()[$index]] = $source->getValue($innerKey);
		}
	}

	protected function getTargetIdentityFromSourceRow(array $row): ?PrimaryKeyValue
	{
		$values = [];
		foreach ($this->relation->getInnerKeys() as $index => $innerKey) {
			if (! array_key_exists($innerKey, $row)) {
				return null;
			}

			$values[$this->relation->getOuterKeys()[$index]] = $row[$innerKey];
		}

		return new PrimaryKeyValue($this->getTargetCollection(), $values);
	}
}
