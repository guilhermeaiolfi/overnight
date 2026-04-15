<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;

abstract class AbstractRestResolver implements RestResolverInterface
{
	public function __construct(
		protected Registry $registry,
		protected int $defaultLimit = 100,
		protected int $maxLimit = 1000
	) {
	}

	// -------------------------------------------------------------------------
	// Nested relation orchestration (shared logic)
	// -------------------------------------------------------------------------

	public function createWithRelations(CollectionInterface $collection, array $input, array $nestedInput): array
	{
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$beforeParent = [];
		$afterParent = [];

		foreach ($nestedInput as $relationName => $relationData) {
			if (!$collection->relations->has($relationName)) {
				continue;
			}

			$relation = $collection->relations->get($relationName);

			if ($relation->isJunction()) {
				$afterParent[$relationName] = $relationData;
				continue;
			}

			$innerKey = $relation->getInnerKey();

			// belongsTo: innerKey !== PK → FK is on parent side
			if ($innerKey !== $pkColumn) {
				if (!is_array($relationData) || !$this->isAssociativeArray($relationData)) {
					$input[$innerKey] = is_array($relationData) ? reset($relationData) : $relationData;
				} else {
					$beforeParent[$relationName] = $relationData;
				}
			} else {
				$afterParent[$relationName] = $relationData;
			}
		}

		$this->beginTransaction();

		try {
			// Create belongsTo targets first
			foreach ($beforeParent as $relationName => $relationData) {
				$relation = $collection->relations->get($relationName);
				$targetCollection = $this->registry->getCollection($relation->getCollection());

				if ($targetCollection === null) {
					continue;
				}

				$created = $this->create($targetCollection, $relationData);
				$input[$relation->getInnerKey()] = $created[$relation->getOuterKey()] ?? null;
			}

			// Create parent
			$parent = $this->create($collection, $input);
			$parentId = (string) ($parent[$pkColumn] ?? '');

			// Create hasMany/hasOne children and handle M2M
			foreach ($afterParent as $relationName => $relationData) {
				$relation = $collection->relations->get($relationName);

				if ($relation->isJunction()) {
					/** @var M2MRelation $relation */
					$this->handleM2M($collection, $parentId, $relation, $relationData);
					continue;
				}

				$targetCollection = $this->registry->getCollection($relation->getCollection());

				if ($targetCollection === null) {
					continue;
				}

				$outerKey = $relation->getOuterKey();
				$parentKeyValue = $parent[$relation->getInnerKey()] ?? $parentId;

				if ($relation->getCardinality() === 'many') {
					foreach ($relationData as $childInput) {
						if (is_array($childInput)) {
							$childInput[$outerKey] = $parentKeyValue;
							$this->create($targetCollection, $childInput);
						}
					}
				} else {
					if (is_array($relationData)) {
						$relationData[$outerKey] = $parentKeyValue;
						$this->create($targetCollection, $relationData);
					}
				}
			}

			$this->commitTransaction();

			return $this->get($collection, $parentId) ?? $parent;
		} catch (\Throwable $e) {
			$this->rollbackTransaction();
			if ($e instanceof RestApiError) {
				throw $e;
			}
			throw new RestApiError('Failed to create with relations: ' . $e->getMessage(), 'DATABASE_ERROR', null, 500, $e);
		}
	}

	public function updateWithRelations(CollectionInterface $collection, string $id, array $input, array $nestedInput): ?array
	{
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$beforeParent = [];
		$afterParent = [];

		foreach ($nestedInput as $relationName => $relationData) {
			if (!$collection->relations->has($relationName)) {
				continue;
			}

			$relation = $collection->relations->get($relationName);

			if ($relation->isJunction()) {
				$afterParent[$relationName] = $relationData;
				continue;
			}

			$innerKey = $relation->getInnerKey();

			if ($innerKey !== $pkColumn) {
				if (!is_array($relationData) || !$this->isAssociativeArray($relationData)) {
					$input[$innerKey] = is_array($relationData) ? reset($relationData) : $relationData;
				} else {
					$beforeParent[$relationName] = $relationData;
				}
			} else {
				$afterParent[$relationName] = $relationData;
			}
		}

		$this->beginTransaction();

		try {
			// Upsert belongsTo targets first
			foreach ($beforeParent as $relationName => $relationData) {
				$relation = $collection->relations->get($relationName);
				$targetCollection = $this->registry->getCollection($relation->getCollection());

				if ($targetCollection === null) {
					continue;
				}

				$targetPkColumn = $this->getPrimaryKeyColumn($targetCollection);

				if (isset($relationData[$targetPkColumn])) {
					$targetId = (string) $relationData[$targetPkColumn];
					unset($relationData[$targetPkColumn]);
					$this->update($targetCollection, $targetId, $relationData);
					$input[$relation->getInnerKey()] = $targetId;
				} else {
					$created = $this->create($targetCollection, $relationData);
					$input[$relation->getInnerKey()] = $created[$relation->getOuterKey()] ?? null;
				}
			}

			// Update parent
			$parent = $this->update($collection, $id, $input);
			if ($parent === null) {
				$this->rollbackTransaction();
				return null;
			}

			// Upsert hasMany/hasOne children and handle M2M
			foreach ($afterParent as $relationName => $relationData) {
				$relation = $collection->relations->get($relationName);

				if ($relation->isJunction()) {
					/** @var M2MRelation $relation */
					$this->handleM2M($collection, $id, $relation, $relationData);
					continue;
				}

				$targetCollection = $this->registry->getCollection($relation->getCollection());

				if ($targetCollection === null) {
					continue;
				}

				$outerKey = $relation->getOuterKey();
				$parentKeyValue = $parent[$relation->getInnerKey()] ?? $id;
				$targetPkColumn = $this->getPrimaryKeyColumn($targetCollection);

				if ($relation->getCardinality() === 'many') {
					foreach ($relationData as $childInput) {
						if (!is_array($childInput)) {
							continue;
						}
						$childInput[$outerKey] = $parentKeyValue;
						if (isset($childInput[$targetPkColumn])) {
							$childId = (string) $childInput[$targetPkColumn];
							unset($childInput[$targetPkColumn]);
							$this->update($targetCollection, $childId, $childInput);
						} else {
							$this->create($targetCollection, $childInput);
						}
					}
				} else {
					if (is_array($relationData)) {
						$relationData[$outerKey] = $parentKeyValue;
						if (isset($relationData[$targetPkColumn])) {
							$childId = (string) $relationData[$targetPkColumn];
							unset($relationData[$targetPkColumn]);
							$this->update($targetCollection, $childId, $relationData);
						} else {
							$this->create($targetCollection, $relationData);
						}
					}
				}
			}

			$this->commitTransaction();

			return $this->get($collection, $id) ?? $parent;
		} catch (\Throwable $e) {
			$this->rollbackTransaction();
			if ($e instanceof RestApiError) {
				throw $e;
			}
			throw new RestApiError('Failed to update with relations: ' . $e->getMessage(), 'DATABASE_ERROR', null, 500, $e);
		}
	}

	// -------------------------------------------------------------------------
	// Transaction hooks — override in concrete resolvers
	// -------------------------------------------------------------------------

	protected function beginTransaction(): void
	{
		// Override in resolvers that support transactions
	}

	protected function commitTransaction(): void
	{
	}

	protected function rollbackTransaction(): void
	{
	}

	// -------------------------------------------------------------------------
	// Shared utilities
	// -------------------------------------------------------------------------

	public function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (is_array($pk) && !empty($pk)) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}

	public function getVisibleFields(CollectionInterface $collection): array
	{
		$visible = [];
		foreach ($collection->fields as $name => $field) {
			if (!$field->isHidden()) {
				$visible[] = $field->getColumn();
			}
		}
		return $visible;
	}

	public function getStringFields(CollectionInterface $collection): array
	{
		$stringTypes = ['string', 'text', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext'];
		$fields = [];

		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden() || $field->isPrimaryKey()) {
				continue;
			}

			try {
				$type = strtolower($field->getType());
				if (in_array($type, $stringTypes, true)) {
					$fields[] = $name;
				}
			} catch (\Throwable) {
				// Field type not set — skip
			}
		}

		return $fields;
	}

	protected function isAssociativeArray(array $arr): bool
	{
		if (empty($arr)) {
			return false;
		}
		return array_keys($arr) !== range(0, count($arr) - 1);
	}

	protected function parseMetaParam(mixed $meta): array
	{
		if ($meta === null) {
			return [];
		}
		return is_array($meta) ? $meta : array_map('trim', explode(',', $meta));
	}

	protected function parseArrayValue(mixed $value): array
	{
		if (is_array($value)) {
			return $value;
		}
		return array_map('trim', explode(',', (string) $value));
	}

	/**
	 * Format raw aggregate DB rows into Directus-style nested response.
	 */
	protected function formatAggregateResult(array $rows, array $aggregates, array $groupBy): array
	{
		$result = [];
		foreach ($rows as $row) {
			$entry = [];

			foreach ($groupBy as $field) {
				if (isset($row[$field])) {
					$entry[$field] = $row[$field];
				}
			}

			foreach ($aggregates as $func => $fields) {
				$func = strtolower($func);
				$fieldList = is_array($fields) ? $fields : [$fields];
				foreach ($fieldList as $field) {
					$alias = $func . '_' . $field;
					if (array_key_exists($alias, $row)) {
						$entry[$func][$field] = $row[$alias];
					}
				}
			}

			$result[] = $entry;
		}
		return $result;
	}
}
