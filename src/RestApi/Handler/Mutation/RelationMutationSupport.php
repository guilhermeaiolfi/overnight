<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\ChildIntent;
use ON\RestApi\Mutation\LinkIntent;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait RelationMutationSupport
{
	public function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): ?PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->extractFromInput($input);
	}

	protected function emptyPayload(): RelationMutationPayload
	{
		return RelationMutationPayload::empty();
	}

	protected function childIntent(array $data, ?CollectionInterface $collection = null): ChildIntent
	{
		return new ChildIntent($collection ?? $this->getTargetCollection(), $data);
	}

	protected function linkIntent(PrimaryKeyValue|int|string $target, ?CollectionInterface $collection = null): LinkIntent
	{
		return new LinkIntent($collection ?? $this->getTargetCollection(), $target);
	}

	protected static function linkTarget(LinkIntent|PrimaryKeyValue|int|string $link): PrimaryKeyValue|int|string
	{
		return $link instanceof LinkIntent ? $link->target : $link;
	}

	protected function primaryKeyCriteria(MutationStateInterface $state): FilterNode
	{
		return PrimaryKeyCriteria::build($state->getCollection(), $this->getPrimaryKeyValueFromState($state));
	}

	protected function hasOperationPayload(array $input): bool
	{
		foreach (['create', 'update', 'delete', 'connect', 'disconnect'] as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}

	protected function isDetailedPayload(mixed $input): bool
	{
		return is_array($input) && MutationInput::isAssociativeArray($input) && $this->hasOperationPayload($input);
	}

	protected function normalizeDetailedPayload(array $input, ?CollectionInterface $collection = null): RelationMutationPayload
	{
		$payload = $this->emptyPayload();
		$collection ??= $this->getTargetCollection();

		foreach (['create', 'update'] as $key) {
			foreach (MutationInput::normalizeRelationItems($input[$key] ?? []) as $item) {
				if (!is_array($item)) {
					continue;
				}

				$payload->{$key}[] = new ChildIntent($collection, $item);
			}
		}

		foreach (MutationInput::normalizeRelationItems($input['delete'] ?? []) as $item) {
			if (is_array($item)) {
				$payload->delete[] = new ChildIntent($collection, $item);
				continue;
			}

			$payload->delete[] = new ChildIntent(
				$collection,
				PrimaryKeyCriteria::normalize($collection, $item)->values()
			);
		}

		foreach (MutationInput::normalizeRelationItems($input['connect'] ?? []) as $item) {
			if ($item instanceof PrimaryKeyValue) {
				$payload->connect[] = new LinkIntent($collection, $item);
				continue;
			}

			if (is_array($item)) {
				$id = $this->getInputPrimaryKeyValue($collection, $item);
				$payload->connect[] = $id !== null ? new LinkIntent($collection, $id) : new ChildIntent($collection, $item);
				continue;
			}

			$payload->connect[] = new LinkIntent($collection, $item);
		}

		foreach (MutationInput::normalizeRelationItems($input['disconnect'] ?? []) as $item) {
			if ($item instanceof PrimaryKeyValue) {
				$payload->disconnect[] = new LinkIntent($collection, $item);
				continue;
			}

			if (is_array($item)) {
				$id = $this->getInputPrimaryKeyValue($collection, $item);
				$payload->disconnect[] = $id !== null ? new LinkIntent($collection, $id) : new ChildIntent($collection, $item);
				continue;
			}

			$payload->disconnect[] = new LinkIntent($collection, $item);
		}

		return $payload;
	}

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
		$fieldNames ??= $this->visibleFieldNames($collection);
		if (!in_array($fieldName, $fieldNames, true)) {
			$fieldNames[] = $fieldName;
		}

		$query = $this->dataSource->select($collection, $fieldNames);
		$query->where($collection->fields->get($fieldName)->getColumn(), $value);

		return array_map(
			fn(array $row): array => $this->mapRowToFieldNames($collection, $row),
			$this->dataSource->fetchAll($query)
		);
	}

	protected function fetchRowsByFields(
		CollectionInterface $collection,
		array $fieldValueMap,
		?array $fieldNames = null
	): array {
		$fieldNames ??= $this->visibleFieldNames($collection);
		foreach (array_keys($fieldValueMap) as $fieldName) {
			if (!in_array((string) $fieldName, $fieldNames, true)) {
				$fieldNames[] = (string) $fieldName;
			}
		}

		$query = $this->dataSource->select($collection, $fieldNames);
		foreach ($fieldValueMap as $fieldName => $value) {
			$query->where($collection->fields->get((string) $fieldName)->getColumn(), $value);
		}

		return array_map(
			fn(array $row): array => $this->mapRowToFieldNames($collection, $row),
			$this->dataSource->fetchAll($query)
		);
	}

	protected function fetchRowByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity
	): ?array {
		$fieldNames = $this->visibleFieldNames($collection);
		foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {
			if (!in_array($fieldName, $fieldNames, true)) {
				$fieldNames[] = $fieldName;
			}
		}

		$query = $this->dataSource->select($collection, $fieldNames);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->dataSource->fetchOne($query);

		return $row === null ? null : $this->mapRowToFieldNames($collection, $row);
	}

	protected function getPrimaryKeyValueFromState(
		MutationStateInterface $state,
		bool $requireReady = true
	): ?PrimaryKeyValue {
		$values = [];

		foreach ($state->getCollection()->getPrimaryKey()->getFieldNames() as $fieldName) {
			$value = $state->getValue($fieldName);
			if ($value instanceof ValueRef) {
				if (!$value->isReady() && $requireReady) {
					return null;
				}

				$values[$fieldName] = $value;
				continue;
			}

			if ($requireReady) {
				$value = $state->resolveValue($value);
			}

			if ($value === null && !$state->isValueReady($fieldName)) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return new PrimaryKeyValue($state->getCollection(), $values);
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

	protected function setSourceRelationValuesFromTargetState(
		MutationStateInterface $source,
		MutationStateInterface $target
	): void {
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$outerKey = $this->relation->outerKeys()[$index];
			$source->setValue($innerKey, ValueRef::forStateField($target, $outerKey));
		}
	}
}
