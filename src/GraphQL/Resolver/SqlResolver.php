<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use ON\DB\DatabaseInterface;
use ON\GraphQL\DataLoader\GenericDataLoader;
use ON\GraphQL\Error\GraphQLUserError;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\RelationInterface;
use PDO;

class SqlResolver implements GraphQLResolverInterface
{
	protected GenericDataLoader $dataLoader;

	public function __construct(
		protected Registry $ormRegistry,
		protected DatabaseInterface $database
	) {
		$this->dataLoader = new GenericDataLoader($ormRegistry);
	}

	public function resolveCollection(Collection $collection, array $args = []): array
	{
		$table = $collection->getTable();
		$where = $this->buildWhereClause($collection, $args);
		$orderBy = $this->buildOrderBy($collection, $args);

		$limit = isset($args['limit']) ? ' LIMIT ' . (int) $args['limit'] : '';
		$offset = isset($args['offset']) ? ' OFFSET ' . (int) $args['offset'] : '';

		$quotedTable = $this->quoteIdentifier($table);

		$countSql = "SELECT COUNT(*) as cnt FROM {$quotedTable} {$where}";
		$values = $this->extractFilterValues($collection, $args);

		$countStmt = $this->database->getConnection()->prepare($countSql);
		$countStmt->execute($values);
		$countRow = $countStmt->fetch(PDO::FETCH_OBJ);
		$totalCount = $countRow ? (int) $countRow->cnt : 0;

		$sql = "SELECT * FROM {$quotedTable} {$where} {$orderBy}{$limit}{$offset}";
		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute($values);
		$items = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

		return [
			'items' => $items,
			'totalCount' => $totalCount,
		];
	}

	public function resolveById(Collection $collection, string $id): ?object
	{
		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);

		$quotedTable = $this->quoteIdentifier($table);
		$quotedPk = $this->quoteIdentifier($pkColumn);
		$sql = "SELECT * FROM {$quotedTable} WHERE {$quotedPk} = ?";

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$id]);

		return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
	}

	public function resolveCreate(Collection $collection, array $input): ?object
	{
		try {
			$table = $collection->getTable();

			$columns = array_keys($input);
			$quotedColumns = array_map(fn(string $col) => $this->quoteIdentifier($col), $columns);
			$placeholders = array_fill(0, count($columns), '?');

			$quotedTable = $this->quoteIdentifier($table);
			$sql = sprintf(
				'INSERT INTO %s (%s) VALUES (%s)',
				$quotedTable,
				implode(', ', $quotedColumns),
				implode(', ', $placeholders)
			);

			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute(array_values($input));

			$lastId = $this->database->getConnection()->lastInsertId();

			return $this->resolveById($collection, (string) $lastId);
		} catch (\PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		try {
			$table = $collection->getTable();
			$pkColumn = $this->getPrimaryKeyColumn($collection);

			$sets = [];
			foreach (array_keys($input) as $column) {
				$sets[] = $this->quoteIdentifier($column) . " = ?";
			}

			$quotedTable = $this->quoteIdentifier($table);
			$quotedPk = $this->quoteIdentifier($pkColumn);
			$sql = sprintf(
				'UPDATE %s SET %s WHERE %s = ?',
				$quotedTable,
				implode(', ', $sets),
				$quotedPk
			);

			$values = array_values($input);
			$values[] = $id;

			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute($values);

			return $this->resolveById($collection, $id);
		} catch (\PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function resolveDelete(Collection $collection, string $id): ?object
	{
		try {
			// Fetch the object before deleting
			$object = $this->resolveById($collection, $id);

			if ($object === null) {
				return null;
			}

			$table = $collection->getTable();
			$pkColumn = $this->getPrimaryKeyColumn($collection);

			$quotedTable = $this->quoteIdentifier($table);
			$quotedPk = $this->quoteIdentifier($pkColumn);
			$sql = "DELETE FROM {$quotedTable} WHERE {$quotedPk} = ?";

			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute([$id]);

			return $stmt->rowCount() > 0 ? $object : null;
		} catch (\PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function resolveNestedCreate(Collection $collection, array $input, array $nestedInput): ?object
	{
		$connection = $this->database->getConnection();

		try {
			$connection->beginTransaction();

			// Create the parent record
			$parent = $this->resolveCreate($collection, $input);

			if ($parent === null) {
				$connection->rollBack();
				return null;
			}

			$pkColumn = $this->getPrimaryKeyColumn($collection);
			$parentId = $parent->{$pkColumn} ?? null;

			// Process nested relations
			foreach ($nestedInput as $relationName => $relationData) {
				if (!$collection->relations->has($relationName)) {
					continue;
				}

				$relation = $collection->relations->get($relationName);
				$targetCollectionName = $relation->getCollection();
				$targetCollection = $this->ormRegistry->getCollection($targetCollectionName);

				if ($targetCollection === null) {
					continue;
				}

				// Cycle convention: innerKey is on source (parent), outerKey is on target (child)
				$innerKey = $relation->getInnerKey();
				$outerKey = $relation->getOuterKey();
				$parentKeyValue = $parent->{$innerKey} ?? $parentId;

				if ($relation->getCardinality() === 'many') {
					foreach ($relationData as $childInput) {
						$childInput[$outerKey] = $parentKeyValue;
						$this->resolveCreate($targetCollection, $childInput);
					}
				} else {
					$relationData[$outerKey] = $parentKeyValue;
					$this->resolveCreate($targetCollection, $relationData);
				}
			}

			$connection->commit();

			// Re-fetch the parent to get fresh data
			return $this->resolveById($collection, (string) $parentId);
		} catch (\PDOException $e) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function resolveRelation(mixed $source, RelationInterface $relation): mixed
	{
		$collectionName = $relation->getCollection();
		$innerKey = $relation->getInnerKey();
		$outerKey = $relation->getOuterKey();

		// innerKey is on the source entity, outerKey is on the target entity (Cycle convention)
		$sourceId = is_array($source)
			? ($source[$innerKey] ?? null)
			: ($source->{$innerKey} ?? null);

		if ($sourceId === null) {
			return $relation->getCardinality() === 'single' ? null : [];
		}

		$collection = $this->ormRegistry->getCollection($collectionName);
		if ($collection === null) {
			throw new \RuntimeException("Collection not found: {$collectionName}");
		}

		$table = $collection->getTable();
		$isSingle = $relation->getCardinality() === 'single';

		$entityKey = $collectionName . ':' . $outerKey;

		return $this->dataLoader->load($entityKey, $sourceId, function (array $batchKeys) use ($table, $outerKey, $isSingle) {
			$placeholders = implode(', ', array_fill(0, count($batchKeys), '?'));
			$flatKeys = [];
			foreach ($batchKeys as $keySet) {
				$flatKeys[] = is_array($keySet) ? reset($keySet) : $keySet;
			}

			$quotedTable = $this->quoteIdentifier($table);
			$quotedOuterKey = $this->quoteIdentifier($outerKey);
			$sql = "SELECT * FROM {$quotedTable} WHERE {$quotedOuterKey} IN ({$placeholders})";
			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute($flatKeys);

			$allResults = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

			$grouped = [];
			foreach ($allResults as $row) {
				$key = $row->{$outerKey} ?? null;
				if ($key !== null) {
					$grouped[$key][] = $row;
				}
			}

			$results = [];
			foreach ($flatKeys as $i => $keyValue) {
				$rows = $grouped[$keyValue] ?? [];
				if ($isSingle) {
					$results[$i] = $rows[0] ?? null;
				} else {
					$results[$i] = $rows;
				}
			}

			return $results;
		});
	}

	public function clearCache(): void
	{
		$this->dataLoader->clear();
	}

	protected function quoteIdentifier(string $identifier): string
	{
		$sanitized = preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
		return "`{$sanitized}`";
	}

	protected function getFilterableFields(Collection $collection): array
	{
		$fields = [];
		foreach ($collection->fields as $name => $field) {
			if (!$field->isPrimaryKey() && $field->isFilterable()) {
				$fields[$name] = $field;
			}
		}
		return $fields;
	}

	protected function buildWhereClause(Collection $collection, array $args): string
	{
		if (empty($args)) {
			return '';
		}

		$conditions = [];
		$filterableFields = $this->getFilterableFields($collection);

		foreach ($args as $argName => $value) {
			if (isset($filterableFields[$argName])) {
				$column = $this->quoteIdentifier($filterableFields[$argName]->getColumn());
				$operator = is_string($value) && str_contains($value, '%') ? 'LIKE' : '=';
				$conditions[] = "{$column} {$operator} ?";
			}
		}

		return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
	}

	protected function buildOrderBy(Collection $collection, array $args): string
	{
		$sort = $args['sort'] ?? null;
		$order = $args['order'] ?? 'ASC';

		if ($sort === null) {
			return '';
		}

		$validFields = [];
		foreach ($collection->fields as $name => $field) {
			$validFields[$name] = $field->getColumn();
		}

		if (!isset($validFields[$sort])) {
			return '';
		}

		$column = $validFields[$sort];
		$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

		return "ORDER BY " . $this->quoteIdentifier($column) . " {$order}";
	}

	protected function extractFilterValues(Collection $collection, array $args): array
	{
		$filterableFields = $this->getFilterableFields($collection);
		$values = [];
		foreach ($args as $argName => $value) {
			if (isset($filterableFields[$argName])) {
				$values[] = $value;
			}
		}
		return $values;
	}

	protected function getPrimaryKeyColumn(Collection $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (\is_array($pk) && ! empty($pk)) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}

	protected function convertDatabaseError(\PDOException $e, Collection $collection): GraphQLUserError
	{
		$message = $e->getMessage();
		$code = $e->getCode();

		// Detect common database errors
		if (str_contains($message, 'UNIQUE constraint') || str_contains($message, 'Duplicate entry')) {
			// Try to extract the field name
			if (preg_match('/column[s]?\s+[`\']?(\w+)/i', $message, $matches)) {
				return new GraphQLUserError(
					"A record with this {$matches[1]} already exists.",
					'DUPLICATE',
					$matches[1]
				);
			}
			return new GraphQLUserError('A record with these values already exists.', 'DUPLICATE');
		}

		if (str_contains($message, 'NOT NULL constraint') || str_contains($message, 'cannot be null')) {
			if (preg_match('/[`\']?(\w+)[`\']?\s+(?:cannot be null|NOT NULL)/i', $message, $matches)) {
				return new GraphQLUserError(
					"The field {$matches[1]} is required.",
					'REQUIRED_FIELD',
					$matches[1]
				);
			}
			return new GraphQLUserError('A required field is missing.', 'REQUIRED_FIELD');
		}

		if (str_contains($message, 'FOREIGN KEY constraint')) {
			return new GraphQLUserError('Referenced record does not exist.', 'FOREIGN_KEY_VIOLATION');
		}

		return new GraphQLUserError('Database operation failed.', 'DATABASE_ERROR', null, $e);
	}
}
