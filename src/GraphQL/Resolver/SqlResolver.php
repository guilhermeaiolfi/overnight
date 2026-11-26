<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;
use ON\DB\DatabaseInterface;
use ON\GraphQL\DataLoader\GenericDataLoader;
use ON\GraphQL\Error\GraphQLUserError;
use PDO;
use PDOException;
use Throwable;

class SqlResolver implements GraphQLResolverInterface
{
	protected GenericDataLoader $dataLoader;

	public function __construct(
		protected Registry $ormRegistry,
		protected DatabaseInterface $database,
		protected int $defaultLimit = 100,
		protected int $maxLimit = 1000,
	) {
		$this->dataLoader = new GenericDataLoader($ormRegistry);
	}

	public function resolveCollection(Collection $collection, array $args = []): array
	{
		$table = $collection->getTable();
		$where = $this->buildWhereClause($collection, $args);
		$orderBy = $this->buildOrderBy($collection, $args);
		$pagination = $this->buildPaginationClause($args);

		$quotedTable = $this->quoteIdentifier($table);
		$selectColumns = $this->buildSelectColumns($collection);

		$countSql = "SELECT COUNT(*) as cnt FROM {$quotedTable} {$where}";
		$values = $this->extractFilterValues($collection, $args);

		$countStmt = $this->database->getConnection()->prepare($countSql);
		$countStmt->execute($values);
		$countRow = $countStmt->fetch(PDO::FETCH_OBJ);
		$totalCount = $countRow ? (int) $countRow->cnt : 0;

		$sql = "SELECT {$selectColumns} FROM {$quotedTable} {$where} {$orderBy}{$pagination}";
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
		$selectColumns = $this->buildSelectColumns($collection);
		$sql = "SELECT {$selectColumns} FROM {$quotedTable} WHERE {$quotedPk} = ?";

		$stmt = $this->database->getConnection()->prepare($sql);
		$stmt->execute([$id]);

		return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
	}

	public function resolveCreate(Collection $collection, array $input): ?object
	{
		return $this->insertRecord($collection, $input);
	}

	public function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		$columnData = $this->filterWritableInput($collection, $input);

		if ($columnData === []) {
			return $this->resolveById($collection, $id);
		}

		try {
			$table = $collection->getTable();
			$pkColumn = $this->getPrimaryKeyColumn($collection);

			$sets = [];
			foreach (array_keys($columnData) as $column) {
				$sets[] = $this->quoteIdentifier($column) . ' = ?';
			}

			$quotedTable = $this->quoteIdentifier($table);
			$quotedPk = $this->quoteIdentifier($pkColumn);
			$sql = sprintf(
				'UPDATE %s SET %s WHERE %s = ?',
				$quotedTable,
				implode(', ', $sets),
				$quotedPk
			);

			$values = array_values($columnData);
			$values[] = $id;

			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute($values);

			return $this->resolveById($collection, $id);
		} catch (PDOException $e) {
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
		} catch (PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function resolveNestedCreate(Collection $collection, array $input, array $nestedInput): ?object
	{
		$connection = $this->database->getConnection();
		$savepoint = null;
		if ($connection->inTransaction()) {
			$savepoint = 'graphql_' . bin2hex(random_bytes(8));
			$connection->exec("SAVEPOINT {$savepoint}");
		} else {
			$connection->beginTransaction();
		}

		try {
			// Create the parent record
			$parent = $this->insertRecord($collection, $input);

			if ($parent === null) {
				$this->rollbackNestedCreate($connection, $savepoint);

				return null;
			}

			$pkColumn = $this->getPrimaryKeyColumn($collection);
			$parentId = $parent->{$pkColumn} ?? null;

			// Process nested relations
			foreach ($nestedInput as $relationName => $relationData) {
				if (! $collection->relations->has($relationName)) {
					continue;
				}

				$relation = $collection->relations->get($relationName);
				$targetCollection = $relation->getCollection();

				if ($targetCollection->isHidden()) {
					continue;
				}

				// Cycle convention: innerKey is on source (parent), outerKey is on target (child)
				$innerKeyColumn = $relation->getInnerField()->getColumn();
				$outerKeyColumn = $relation->getOuterField()->getColumn();
				$parentKeyValue = $parent->{$innerKeyColumn} ?? $parentId;

				if ($relation->getCardinality()->isMany()) {
					foreach ($relationData as $childInput) {
						if (! is_array($childInput)) {
							continue;
						}
						$this->insertRecord($targetCollection, $childInput, [$outerKeyColumn => $parentKeyValue]);
					}
				} elseif (is_array($relationData)) {
					$this->insertRecord($targetCollection, $relationData, [$outerKeyColumn => $parentKeyValue]);
				}
			}

			// Re-fetch the parent before committing, so a failed read rolls back as well.
			$parent = $this->resolveById($collection, (string) $parentId);
			if ($savepoint === null) {
				$connection->commit();
			} else {
				$connection->exec("RELEASE SAVEPOINT {$savepoint}");
			}

			return $parent;
		} catch (Throwable $e) {
			$this->rollbackNestedCreate($connection, $savepoint);

			if ($e instanceof GraphQLUserError) {
				throw $e;
			}

			if ($e instanceof PDOException) {
				throw $this->convertDatabaseError($e, $collection);
			}

			throw $e;
		}
	}

	private function rollbackNestedCreate(PDO $connection, ?string $savepoint): void
	{
		if (! $connection->inTransaction()) {
			return;
		}

		if ($savepoint === null) {
			$connection->rollBack();
		} else {
			$connection->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
			$connection->exec("RELEASE SAVEPOINT {$savepoint}");
		}
	}

	public function resolveRelation(mixed $source, RelationInterface $relation): mixed
	{
		$collection = $relation->getCollection();
		$isSingle = $relation->getCardinality()->isSingle();

		if ($collection->isHidden()) {
			return $isSingle ? null : [];
		}

		$collectionName = $collection->getName();
		$innerKey = $relation->getInnerField()->getColumn();
		$outerKey = $relation->getOuterField()->getColumn();

		// innerKey is on the source entity, outerKey is on the target entity (Cycle convention)
		$sourceId = is_array($source)
			? ($source[$innerKey] ?? null)
			: ($source->{$innerKey} ?? null);

		if ($sourceId === null) {
			return $isSingle ? null : [];
		}

		$table = $collection->getTable();
		$cardinality = $isSingle ? 'single' : 'many';
		$entityKey = $collectionName . ':' . $outerKey . ':' . $cardinality;
		$selectColumns = $this->buildSelectColumns($collection, [$outerKey]);

		return $this->dataLoader->load($entityKey, $sourceId, function (array $batchKeys) use ($table, $outerKey, $isSingle, $selectColumns) {
			$placeholders = implode(', ', array_fill(0, count($batchKeys), '?'));
			$flatKeys = [];
			foreach ($batchKeys as $keySet) {
				$flatKeys[] = is_array($keySet) ? reset($keySet) : $keySet;
			}

			$quotedTable = $this->quoteIdentifier($table);
			$quotedOuterKey = $this->quoteIdentifier($outerKey);
			$sql = "SELECT {$selectColumns} FROM {$quotedTable} WHERE {$quotedOuterKey} IN ({$placeholders})";
			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute($flatKeys);

			$allResults = $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];

			$grouped = [];
			foreach ($allResults as $row) {
				$key = $row->{$outerKey} ?? null;
				if ($key !== null) {
					$grouped[(string) $key][] = $row;
				}
			}

			$results = [];
			foreach ($flatKeys as $i => $keyValue) {
				$rows = $grouped[(string) $keyValue] ?? [];
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

	/**
	 * @param array<string, mixed> $forcedColumns Column name => value enforced internally (e.g. parent FK on nested create)
	 */
	protected function insertRecord(Collection $collection, array $input, array $forcedColumns = []): ?object
	{
		$columnData = $this->filterWritableInput($collection, $input);

		foreach ($forcedColumns as $column => $value) {
			$columnData[$column] = $value;
		}

		if ($columnData === []) {
			throw new GraphQLUserError('No valid fields provided.', 'INVALID_INPUT');
		}

		try {
			$table = $collection->getTable();
			$columns = array_keys($columnData);
			$quotedColumns = array_map(fn (string $col) => $this->quoteIdentifier($col), $columns);
			$placeholders = array_fill(0, count($columns), '?');

			$quotedTable = $this->quoteIdentifier($table);
			$sql = sprintf(
				'INSERT INTO %s (%s) VALUES (%s)',
				$quotedTable,
				implode(', ', $quotedColumns),
				implode(', ', $placeholders)
			);

			$stmt = $this->database->getConnection()->prepare($sql);
			$stmt->execute(array_values($columnData));

			$lastId = $this->database->getConnection()->lastInsertId();

			return $this->resolveById($collection, (string) $lastId);
		} catch (PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	/**
	 * Whitelist input keys strictly against non-hidden, non-PK collection fields
	 * and map each field name to its backing database column.
	 *
	 * @return array<string, mixed>
	 */
	protected function filterWritableInput(Collection $collection, array $input): array
	{
		$columnData = [];

		foreach ($input as $fieldName => $value) {
			if (! is_string($fieldName) || ! $collection->fields->has($fieldName)) {
				continue;
			}

			$field = $collection->fields->get($fieldName);
			if ($field->isPrimaryKey() || $field->isHidden()) {
				continue;
			}

			$columnData[$field->getColumn()] = $value;
		}

		return $columnData;
	}

	/**
	 * Build an explicit SELECT column list containing only non-hidden fields
	 * plus relation inner keys and any extra required columns (e.g. relation outerKey).
	 *
	 * @param list<string> $extraColumns
	 */
	protected function buildSelectColumns(Collection $collection, array $extraColumns = []): string
	{
		$columns = [];

		foreach ($collection->fields as $field) {
			if (! $field->isHidden()) {
				$columns[$field->getColumn()] = true;
			}
		}

		foreach ($collection->relations as $relation) {
			$target = $this->ormRegistry->getCollection($relation->getCollectionName());
			if ($target !== null && $target->isHidden()) {
				continue;
			}

			foreach ($relation->getInnerKeys() as $innerKeyName) {
				if ($collection->fields->has($innerKeyName)) {
					$columns[$collection->fields->get($innerKeyName)->getColumn()] = true;
				}
			}
		}

		foreach ($extraColumns as $extra) {
			if ($extra !== '') {
				$columns[$extra] = true;
			}
		}

		if ($columns === []) {
			$columns[$this->getPrimaryKeyColumn($collection)] = true;
		}

		return implode(', ', array_map(
			fn (string $col) => $this->quoteIdentifier($col),
			array_keys($columns)
		));
	}

	protected function buildPaginationClause(array $args): string
	{
		$limit = null;

		if (isset($args['limit'])) {
			$limit = max(0, (int) $args['limit']);
			if ($this->maxLimit > 0) {
				$limit = min($limit, $this->maxLimit);
			}
		} elseif ($this->defaultLimit > 0) {
			$limit = $this->maxLimit > 0
				? min($this->defaultLimit, $this->maxLimit)
				: $this->defaultLimit;
		} elseif ($this->maxLimit > 0) {
			$limit = $this->maxLimit;
		}

		$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : null;

		// SQLite and MySQL reject OFFSET without LIMIT
		if ($offset !== null && $limit === null) {
			$limit = PHP_INT_MAX;
		}

		$clause = '';
		if ($limit !== null) {
			$clause .= ' LIMIT ' . $limit;
		}
		if ($offset !== null) {
			$clause .= ' OFFSET ' . $offset;
		}

		return $clause;
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
			if (! $field->isPrimaryKey() && ! $field->isHidden() && $field->isFilterable()) {
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

		if (! is_string($sort) || $sort === '') {
			return '';
		}

		$validFields = [];
		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden()) {
				continue;
			}
			$validFields[$name] = $field->getColumn();
		}

		if (! isset($validFields[$sort])) {
			return '';
		}

		$column = $validFields[$sort];
		$order = is_string($order) && strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

		return 'ORDER BY ' . $this->quoteIdentifier($column) . " {$order}";
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
		$pk = $collection->getPrimaryKeyFields();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (is_array($pk) && ! empty($pk)) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}

	protected function convertDatabaseError(PDOException $e, Collection $collection): GraphQLUserError
	{
		$message = $e->getMessage();

		// Detect common database errors
		if (str_contains($message, 'UNIQUE constraint') || str_contains($message, 'Duplicate entry')) {
			// Try to extract the field name
			if (preg_match('/column[s]?\s+[`\']?(\w+)/i', $message, $matches)) {
				return new GraphQLUserError(
					"A record with this {$matches[1]} already exists.",
					'DUPLICATE',
					$matches[1],
					$e
				);
			}

			return new GraphQLUserError('A record with these values already exists.', 'DUPLICATE', null, $e);
		}

		if (str_contains($message, 'NOT NULL constraint') || str_contains($message, 'cannot be null')) {
			if (preg_match('/[`\']?(\w+)[`\']?\s+(?:cannot be null|NOT NULL)/i', $message, $matches)) {
				return new GraphQLUserError(
					"The field {$matches[1]} is required.",
					'REQUIRED_FIELD',
					$matches[1],
					$e
				);
			}

			return new GraphQLUserError('A required field is missing.', 'REQUIRED_FIELD', null, $e);
		}

		if (str_contains($message, 'FOREIGN KEY constraint')) {
			return new GraphQLUserError('Referenced record does not exist.', 'FOREIGN_KEY_VIOLATION', null, $e);
		}

		return new GraphQLUserError('Database operation failed.', 'DATABASE_ERROR', null, $e);
	}
}
