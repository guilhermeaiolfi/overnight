<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\DB\DatabaseInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\RestDataLoader;
use PDO;

class SqlRestResolver extends AbstractRestResolver
{
	protected RestDataLoader $dataLoader;

	public function __construct(
		Registry $registry,
		protected DatabaseInterface $database,
		protected SqlFilterParser $filterParser,
		int $defaultLimit = 100,
		int $maxLimit = 1000
	) {
		parent::__construct($registry, $defaultLimit, $maxLimit);
		$this->dataLoader = new RestDataLoader();
	}

	// -------------------------------------------------------------------------
	// Transaction hooks — PDO transactions
	// -------------------------------------------------------------------------

	protected function beginTransaction(): void
	{
		$this->database->getConnection()->beginTransaction();
	}

	protected function commitTransaction(): void
	{
		$connection = $this->database->getConnection();
		if ($connection->inTransaction()) {
			$connection->commit();
		}
	}

	protected function rollbackTransaction(): void
	{
		$connection = $this->database->getConnection();
		if ($connection->inTransaction()) {
			$connection->rollBack();
		}
	}

	// -------------------------------------------------------------------------
	// CRUD operations
	// -------------------------------------------------------------------------

	public function list(CollectionInterface $collection, array $params = []): array
	{
		$table = $collection->getTable();
		$quotedTable = $this->quoteIdentifier($table);
		$connection = $this->database->getConnection();

		// Field selection (pre-parsed by middleware)
		$fields = $params['fields'] ?? ['columns' => [], 'relations' => []];
		$selectColumns = $this->buildSelectColumns($collection, $fields['columns']);

		// Build WHERE from filters + search
		$values = [];
		$whereSql = $this->buildWhereClause($collection, $params['filter'] ?? [], $values);

		$searchClause = '';
		if (!empty($params['search'])) {
			$searchClause = $this->buildSearchClause($collection, $params['search'], $values);
		}

		// Combine WHERE parts
		$fullWhere = '';
		if ($whereSql !== '' && $searchClause !== '') {
			$fullWhere = $whereSql . ' AND ' . $searchClause;
		} elseif ($whereSql !== '') {
			$fullWhere = $whereSql;
		} elseif ($searchClause !== '') {
			$fullWhere = 'WHERE ' . $searchClause;
		}

		// Metadata: total_count (no filters), filter_count (with filters)
		$meta = [];
		$requestedMeta = $params['meta'] ?? [];

		if (in_array('total_count', $requestedMeta, true)) {
			$countSql = "SELECT COUNT(*) as cnt FROM {$quotedTable}";
			$countStmt = $connection->prepare($countSql);
			$countStmt->execute();
			$meta['total_count'] = (int) $countStmt->fetchColumn();
		}

		if (in_array('filter_count', $requestedMeta, true)) {
			$filterCountSql = "SELECT COUNT(*) as cnt FROM {$quotedTable} {$fullWhere}";
			$filterCountStmt = $connection->prepare($filterCountSql);
			$filterCountStmt->execute($values);
			$meta['filter_count'] = (int) $filterCountStmt->fetchColumn();
		}

		// ORDER BY, LIMIT/OFFSET
		$orderBy = $this->buildOrderByClause($collection, $params['sort'] ?? null);
		$limitOffset = $this->buildLimitOffset($params);

		$sql = "SELECT {$selectColumns} FROM {$quotedTable} {$fullWhere} {$orderBy} {$limitOffset}";
		$stmt = $connection->prepare($sql);
		$stmt->execute($values);
		$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		// Exclude hidden fields
		$visibleFields = $this->getVisibleFields($collection);
		$items = array_map(function (array $item) use ($visibleFields) {
			return array_intersect_key($item, array_flip($visibleFields));
		}, $items);

		// Load relations
		if (!empty($fields['relations'])) {
			$items = $this->loadRelations(
				$collection,
				$items,
				$fields['relations'],
				$params['deep'] ?? []
			);
		}

		return [
			'items' => $items,
			'meta' => $meta,
		];
	}

	public function get(CollectionInterface $collection, string $id, array $params = []): ?array
	{
		$table = $collection->getTable();
		$pkColumn = $this->getPrimaryKeyColumn($collection);
		$connection = $this->database->getConnection();

		// Field selection (pre-parsed by middleware)
		$fields = $params['fields'] ?? ['columns' => [], 'relations' => []];
		$selectColumns = $this->buildSelectColumns($collection, $fields['columns']);

		$quotedTable = $this->quoteIdentifier($table);
		$quotedPk = $this->quoteIdentifier($pkColumn);
		$sql = "SELECT {$selectColumns} FROM {$quotedTable} WHERE {$quotedPk} = ?";

		$stmt = $connection->prepare($sql);
		$stmt->execute([$id]);
		$item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

		if ($item === null) {
			return null;
		}

		// Exclude hidden fields
		$visibleFields = $this->getVisibleFields($collection);
		$item = array_intersect_key($item, array_flip($visibleFields));

		// Load relations
		if (!empty($fields['relations'])) {
			$items = $this->loadRelations(
				$collection,
				[$item],
				$fields['relations'],
				$params['deep'] ?? []
			);
			$item = $items[0];
		}

		return $item;
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		try {
			$table = $collection->getTable();
			$connection = $this->database->getConnection();

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

			$stmt = $connection->prepare($sql);
			$stmt->execute(array_values($input));

			$lastId = $connection->lastInsertId();

			return $this->get($collection, (string) $lastId) ?? [];
		} catch (\PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function update(CollectionInterface $collection, string $id, array $input): ?array
	{
		try {
			$table = $collection->getTable();
			$pkColumn = $this->getPrimaryKeyColumn($collection);
			$connection = $this->database->getConnection();

			if (empty($input)) {
				return $this->get($collection, $id);
			}

			$sets = [];
			foreach (array_keys($input) as $column) {
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

			$values = array_values($input);
			$values[] = $id;

			$stmt = $connection->prepare($sql);
			$stmt->execute($values);

			return $this->get($collection, $id);
		} catch (\PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function delete(CollectionInterface $collection, string $id): bool
	{
		try {
			$table = $collection->getTable();
			$pkColumn = $this->getPrimaryKeyColumn($collection);
			$connection = $this->database->getConnection();

			// Fetch item first to confirm it exists
			$item = $this->get($collection, $id);
			if ($item === null) {
				return false;
			}

			$quotedTable = $this->quoteIdentifier($table);
			$quotedPk = $this->quoteIdentifier($pkColumn);
			$sql = "DELETE FROM {$quotedTable} WHERE {$quotedPk} = ?";

			$stmt = $connection->prepare($sql);
			$stmt->execute([$id]);

			return $stmt->rowCount() > 0;
		} catch (\PDOException $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function handleM2M(CollectionInterface $collection, string $parentId, M2MRelation $relation, array $operations): void
	{
		$connection = $this->database->getConnection();
		$through = $relation->through;

		$junctionTable = $through->getCollection();
		$throughInnerKey = $through->getInnerKey(); // FK pointing to parent
		$throughOuterKey = $through->getOuterKey(); // FK pointing to target

		$targetCollectionName = $relation->getCollection();
		$targetCollection = $this->registry->getCollection($targetCollectionName);

		$quotedJunction = $this->quoteIdentifier($junctionTable);
		$quotedThroughInner = $this->quoteIdentifier($throughInnerKey);
		$quotedThroughOuter = $this->quoteIdentifier($throughOuterKey);

		// Process create operations first — create target records, then connect
		if (!empty($operations['create']) && $targetCollection !== null) {
			foreach ($operations['create'] as $createInput) {
				if (!is_array($createInput)) {
					continue;
				}
				$created = $this->create($targetCollection, $createInput);
				$targetPkColumn = $this->getPrimaryKeyColumn($targetCollection);
				$targetId = $created[$targetPkColumn] ?? null;

				if ($targetId !== null) {
					$sql = "INSERT OR IGNORE INTO {$quotedJunction} ({$quotedThroughInner}, {$quotedThroughOuter}) VALUES (?, ?)";
					$stmt = $connection->prepare($sql);
					$stmt->execute([$parentId, $targetId]);
				}
			}
		}

		// Connect existing records
		if (!empty($operations['connect'])) {
			foreach ($operations['connect'] as $targetId) {
				$sql = "INSERT OR IGNORE INTO {$quotedJunction} ({$quotedThroughInner}, {$quotedThroughOuter}) VALUES (?, ?)";
				$stmt = $connection->prepare($sql);
				$stmt->execute([$parentId, $targetId]);
			}
		}

		// Disconnect records
		if (!empty($operations['disconnect'])) {
			foreach ($operations['disconnect'] as $targetId) {
				$sql = "DELETE FROM {$quotedJunction} WHERE {$quotedThroughInner} = ? AND {$quotedThroughOuter} = ?";
				$stmt = $connection->prepare($sql);
				$stmt->execute([$parentId, $targetId]);
			}
		}
	}

	public function aggregate(CollectionInterface $collection, array $params = []): array
	{
		$table = $collection->getTable();
		$quotedTable = $this->quoteIdentifier($table);
		$connection = $this->database->getConnection();

		$aggregates = $params['aggregate'] ?? [];
		$groupBy = $params['groupBy'] ?? [];

		if (empty($aggregates)) {
			return [];
		}

		// Build aggregate SELECT
		$selectParts = $this->buildAggregateSelect($collection, $aggregates, $groupBy);
		$groupByClause = $this->buildGroupByClause($collection, $groupBy);

		// Build WHERE from filters + search
		$values = [];
		$whereSql = $this->buildWhereClause($collection, $params['filter'] ?? [], $values);

		$searchClause = '';
		if (!empty($params['search'])) {
			$searchClause = $this->buildSearchClause($collection, $params['search'], $values);
		}

		$fullWhere = '';
		if ($whereSql !== '' && $searchClause !== '') {
			$fullWhere = $whereSql . ' AND ' . $searchClause;
		} elseif ($whereSql !== '') {
			$fullWhere = $whereSql;
		} elseif ($searchClause !== '') {
			$fullWhere = 'WHERE ' . $searchClause;
		}

		$sql = "SELECT {$selectParts} FROM {$quotedTable} {$fullWhere} {$groupByClause}";
		$stmt = $connection->prepare($sql);
		$stmt->execute($values);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		return $this->formatAggregateResult($rows, $aggregates, $groupBy);
	}

	public function clearCache(): void
	{
		$this->dataLoader->clear();
	}

	// -------------------------------------------------------------------------
	// SQL Building Helpers
	// -------------------------------------------------------------------------

	protected function buildSelectColumns(CollectionInterface $collection, ?array $fields): string
	{
		if ($fields === null || empty($fields)) {
			return '*';
		}

		// Always include PK
		$pkColumn = $this->getPrimaryKeyColumn($collection);
		$columns = $fields;

		// Map field names to column names
		$quotedColumns = [];
		foreach ($columns as $fieldName) {
			if ($collection->fields->has($fieldName)) {
				$field = $collection->fields->get($fieldName);
				$quotedColumns[] = $this->quoteIdentifier($field->getColumn());
			}
		}

		if (empty($quotedColumns)) {
			return '*';
		}

		return implode(', ', $quotedColumns);
	}

	protected function buildWhereClause(CollectionInterface $collection, array $filters, array &$values): string
	{
		if (empty($filters)) {
			return '';
		}

		$result = $this->filterParser->parse($collection, $filters);
		if ($result['sql'] === '') {
			return '';
		}

		$values = array_merge($values, $result['values']);

		return $result['sql'];
	}

	protected function buildSearchClause(CollectionInterface $collection, string $search, array &$values): string
	{
		$stringFields = $this->getStringFields($collection);

		if (empty($stringFields)) {
			return '';
		}

		$conditions = [];
		foreach ($stringFields as $fieldName) {
			$field = $collection->fields->get($fieldName);
			$conditions[] = $this->quoteIdentifier($field->getColumn()) . ' LIKE ?';
			$values[] = '%' . $search . '%';
		}

		return '(' . implode(' OR ', $conditions) . ')';
	}

	protected function buildOrderByClause(CollectionInterface $collection, ?string $sort): string
	{
		if ($sort === null || $sort === '') {
			return '';
		}

		$parts = array_map('trim', explode(',', $sort));
		$clauses = [];

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}

			// Validate field exists
			if (!$collection->fields->has($part)) {
				continue;
			}

			$field = $collection->fields->get($part);
			$clauses[] = $this->quoteIdentifier($field->getColumn()) . ' ' . $direction;
		}

		if (empty($clauses)) {
			return '';
		}

		return 'ORDER BY ' . implode(', ', $clauses);
	}

	protected function buildLimitOffset(array $params): string
	{
		$limit = isset($params['limit']) ? (int) $params['limit'] : $this->defaultLimit;
		$limit = min($limit, $this->maxLimit);
		if ($limit < 1) {
			$limit = $this->defaultLimit;
		}

		$offset = null;

		// Explicit offset takes precedence over page
		if (isset($params['offset'])) {
			$offset = (int) $params['offset'];
		} elseif (isset($params['page'])) {
			$page = max(1, (int) $params['page']);
			$offset = ($page - 1) * $limit;
		}

		$sql = "LIMIT {$limit}";
		if ($offset !== null && $offset > 0) {
			$sql .= " OFFSET {$offset}";
		}

		return $sql;
	}

	protected function buildAggregateSelect(CollectionInterface $collection, array $aggregates, array $groupBy): string
	{
		$parts = [];

		// Add group-by columns first
		foreach ($groupBy as $field) {
			if ($collection->fields->has($field)) {
				$col = $collection->fields->get($field)->getColumn();
				$parts[] = $this->quoteIdentifier($col) . ' AS ' . $this->quoteIdentifier($field);
			}
		}

		// Add aggregate functions
		$allowedFunctions = ['count', 'sum', 'avg', 'min', 'max'];
		foreach ($aggregates as $func => $fields) {
			$func = strtolower($func);
			if (!in_array($func, $allowedFunctions, true)) {
				continue;
			}

			$fieldList = is_array($fields) ? $fields : [$fields];
			foreach ($fieldList as $field) {
				if ($field === '*' && $func === 'count') {
					$parts[] = 'COUNT(*) AS ' . $this->quoteIdentifier('count_*');
				} elseif ($collection->fields->has($field)) {
					$col = $collection->fields->get($field)->getColumn();
					$alias = $func . '_' . $field;
					$funcUpper = strtoupper($func);
					$parts[] = "{$funcUpper}({$this->quoteIdentifier($col)}) AS {$this->quoteIdentifier($alias)}";
				}
			}
		}

		return !empty($parts) ? implode(', ', $parts) : 'COUNT(*) AS ' . $this->quoteIdentifier('count_*');
	}

	protected function buildGroupByClause(CollectionInterface $collection, array $groupBy): string
	{
		if (empty($groupBy)) {
			return '';
		}

		$columns = [];
		foreach ($groupBy as $field) {
			if ($collection->fields->has($field)) {
				$columns[] = $this->quoteIdentifier($collection->fields->get($field)->getColumn());
			}
		}

		if (empty($columns)) {
			return '';
		}

		return 'GROUP BY ' . implode(', ', $columns);
	}

	// -------------------------------------------------------------------------
	// Relation Loading
	// -------------------------------------------------------------------------

	protected function loadRelations(CollectionInterface $collection, array $items, array $relationFields, array $deepParams = []): array
	{
		if (empty($items) || empty($relationFields)) {
			return $items;
		}

		$connection = $this->database->getConnection();

		foreach ($relationFields as $relationName => $relParsed) {
			if (!$collection->relations->has($relationName)) {
				continue;
			}

			$relation = $collection->relations->get($relationName);
			$targetCollectionName = $relation->getCollection();
			$targetCollection = $this->registry->getCollection($targetCollectionName);

			if ($targetCollection === null) {
				continue;
			}

			$innerKey = $relation->getInnerKey();
			$outerKey = $relation->getOuterKey();

			// Collect parent values for the innerKey
			$parentIds = [];
			foreach ($items as $item) {
				$val = $item[$innerKey] ?? null;
				if ($val !== null && !in_array($val, $parentIds, true)) {
					$parentIds[] = $val;
				}
			}

			if (empty($parentIds)) {
				// Set null/empty for all items
				foreach ($items as &$item) {
					$item[$relationName] = $relation->getCardinality() === 'single' ? null : [];
				}
				unset($item);
				continue;
			}

			// Determine columns to load for the relation
			$relColumns = null;
			if (!empty($relParsed['columns'])) {
				$relColumns = [];
				foreach ($relParsed['columns'] as $fieldName) {
					if ($targetCollection->fields->has($fieldName)) {
						$relColumns[] = $targetCollection->fields->get($fieldName)->getColumn();
					}
				}
				// Always include the FK column
				$outerCol = $outerKey;
				if ($targetCollection->fields->has($outerKey)) {
					$outerCol = $targetCollection->fields->get($outerKey)->getColumn();
				}
				if (!in_array($outerCol, $relColumns, true)) {
					$relColumns[] = $outerCol;
				}
			}

			// Deep params for this relation
			$relDeep = $deepParams[$relationName] ?? [];
			$relOrderBy = null;
			$relLimit = null;
			$relOffset = null;

			if (!empty($relDeep['_sort'])) {
				$relOrderBy = $this->buildOrderByClauseRaw($targetCollection, $relDeep['_sort']);
			}
			if (isset($relDeep['_limit'])) {
				$relLimit = (int) $relDeep['_limit'];
			}
			if (isset($relDeep['_offset'])) {
				$relOffset = (int) $relDeep['_offset'];
			}

			// Handle M2M via junction table
			if ($relation->isJunction() && $relation instanceof M2MRelation) {
				$items = $this->loadM2MRelation(
					$relation,
					$targetCollection,
					$items,
					$innerKey,
					$relationName,
					$relColumns,
					$relOrderBy,
					$relLimit,
					$relOffset,
					$connection
				);
				continue;
			}

			// Standard relation: batch load via DataLoader
			$grouped = $this->dataLoader->loadBatch(
				$targetCollection->getTable(),
				$outerKey,
				$parentIds,
				$connection,
				$relColumns,
				null,
				$relOrderBy,
				$relLimit,
				$relOffset
			);

			// Exclude hidden fields from related items
			$visibleFields = $this->getVisibleFields($targetCollection);

			$isSingle = $relation->getCardinality() === 'single';

			foreach ($items as &$item) {
				$parentVal = $item[$innerKey] ?? null;
				$related = $grouped[$parentVal] ?? [];

				// Filter hidden fields
				$related = array_map(function (array $row) use ($visibleFields) {
					return array_intersect_key($row, array_flip($visibleFields));
				}, $related);

				if ($isSingle) {
					$item[$relationName] = $related[0] ?? null;
				} else {
					$item[$relationName] = $related;
				}
			}
			unset($item);

			// Load nested relations if requested
			if (!empty($relParsed['relations'])) {
				foreach ($items as &$item) {
					$relData = $item[$relationName] ?? null;
					if ($relData === null) {
						continue;
					}

					if ($isSingle) {
						$nested = $this->loadRelations($targetCollection, [$relData], $relParsed['relations']);
						$item[$relationName] = $nested[0] ?? null;
					} else {
						$item[$relationName] = $this->loadRelations($targetCollection, $relData, $relParsed['relations']);
					}
				}
				unset($item);
			}
		}

		return $items;
	}

	protected function loadM2MRelation(
		M2MRelation $relation,
		CollectionInterface $targetCollection,
		array $items,
		string $innerKey,
		string $relationName,
		?array $relColumns,
		?string $relOrderBy,
		?int $relLimit,
		?int $relOffset,
		\PDO $connection
	): array {
		$through = $relation->through;
		$junctionTable = $through->getCollection();
		$throughInnerKey = $through->getInnerKey();
		$throughOuterKey = $through->getOuterKey();
		$targetPkColumn = $this->getPrimaryKeyColumn($targetCollection);

		// Collect parent IDs
		$parentIds = [];
		foreach ($items as $item) {
			$val = $item[$innerKey] ?? null;
			if ($val !== null && !in_array($val, $parentIds, true)) {
				$parentIds[] = $val;
			}
		}

		if (empty($parentIds)) {
			foreach ($items as &$item) {
				$item[$relationName] = [];
			}
			unset($item);
			return $items;
		}

		// Load junction rows
		$quotedJunction = $this->quoteIdentifier($junctionTable);
		$quotedThroughInner = $this->quoteIdentifier($throughInnerKey);
		$quotedThroughOuter = $this->quoteIdentifier($throughOuterKey);

		$placeholders = implode(', ', array_fill(0, count($parentIds), '?'));
		$junctionSql = "SELECT {$quotedThroughInner}, {$quotedThroughOuter} FROM {$quotedJunction} WHERE {$quotedThroughInner} IN ({$placeholders})";
		$junctionStmt = $connection->prepare($junctionSql);
		$junctionStmt->execute($parentIds);
		$junctionRows = $junctionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		// Group target IDs by parent
		$parentToTargets = [];
		$allTargetIds = [];
		foreach ($junctionRows as $row) {
			$pId = $row[$throughInnerKey];
			$tId = $row[$throughOuterKey];
			$parentToTargets[$pId][] = $tId;
			if (!in_array($tId, $allTargetIds, true)) {
				$allTargetIds[] = $tId;
			}
		}

		// Batch-load target records
		$targetItems = [];
		if (!empty($allTargetIds)) {
			$targetTable = $targetCollection->getTable();
			$quotedTarget = $this->quoteIdentifier($targetTable);
			$quotedTargetPk = $this->quoteIdentifier($targetPkColumn);

			$columnList = $relColumns !== null
				? implode(', ', array_map([$this, 'quoteIdentifier'], $relColumns))
				: '*';

			$targetPlaceholders = implode(', ', array_fill(0, count($allTargetIds), '?'));
			$targetSql = "SELECT {$columnList} FROM {$quotedTarget} WHERE {$quotedTargetPk} IN ({$targetPlaceholders})";

			if ($relOrderBy !== null && $relOrderBy !== '') {
				$targetSql .= " ORDER BY {$relOrderBy}";
			}

			$targetStmt = $connection->prepare($targetSql);
			$targetStmt->execute($allTargetIds);
			$targetRows = $targetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

			foreach ($targetRows as $row) {
				$targetItems[$row[$targetPkColumn]] = $row;
			}
		}

		// Assign to parent items
		$visibleFields = $this->getVisibleFields($targetCollection);

		foreach ($items as &$item) {
			$parentVal = $item[$innerKey] ?? null;
			$targetIds = $parentToTargets[$parentVal] ?? [];

			$related = [];
			foreach ($targetIds as $tId) {
				if (isset($targetItems[$tId])) {
					$related[] = array_intersect_key($targetItems[$tId], array_flip($visibleFields));
				}
			}

			// Apply per-parent limit/offset
			if ($relLimit !== null || $relOffset !== null) {
				$off = $relOffset ?? 0;
				$related = array_slice($related, $off, $relLimit);
			}

			$item[$relationName] = $related;
		}
		unset($item);

		return $items;
	}

	/**
	 * Build ORDER BY from a raw sort string for use in DataLoader (returns clause without "ORDER BY" prefix).
	 */
	protected function buildOrderByClauseRaw(CollectionInterface $collection, string $sort): ?string
	{
		$parts = array_map('trim', explode(',', $sort));
		$clauses = [];

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}

			if (!$collection->fields->has($part)) {
				continue;
			}

			$field = $collection->fields->get($part);
			$clauses[] = $this->quoteIdentifier($field->getColumn()) . ' ' . $direction;
		}

		return !empty($clauses) ? implode(', ', $clauses) : null;
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	protected function quoteIdentifier(string $identifier): string
	{
		$sanitized = preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
		return "`{$sanitized}`";
	}

	protected function convertDatabaseError(\PDOException $e, CollectionInterface $collection): RestApiError
	{
		$message = $e->getMessage();

		if (str_contains($message, 'UNIQUE constraint') || str_contains($message, 'Duplicate entry')) {
			if (preg_match('/column[s]?\s+[`\']?(\w+)/i', $message, $matches)) {
				return new RestApiError(
					"A record with this {$matches[1]} already exists.",
					'DUPLICATE',
					$matches[1],
					409,
					$e
				);
			}
			return new RestApiError('A record with these values already exists.', 'DUPLICATE', null, 409, $e);
		}

		if (str_contains($message, 'NOT NULL constraint') || str_contains($message, 'cannot be null')) {
			if (preg_match('/[`\']?(\w+)[`\']?\s+(?:cannot be null|NOT NULL)/i', $message, $matches)) {
				return new RestApiError(
					"The field {$matches[1]} is required.",
					'REQUIRED_FIELD',
					$matches[1],
					400,
					$e
				);
			}
			return new RestApiError('A required field is missing.', 'REQUIRED_FIELD', null, 400, $e);
		}

		if (str_contains($message, 'FOREIGN KEY constraint')) {
			return new RestApiError('Referenced record does not exist.', 'FOREIGN_KEY_VIOLATION', null, 400, $e);
		}

		return new RestApiError('Database operation failed.', 'DATABASE_ERROR', null, 500, $e);
	}
}
