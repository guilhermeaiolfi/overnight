<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\DB\DatabaseInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\AbstractRestResolver;
use PDO;

class SqlRestResolver extends AbstractRestResolver
{
	protected RestDataLoader $dataLoader;
	protected ?CycleDatabaseInterface $dbal = null;
	protected SqlFilterApplier $filterApplier;

	public function __construct(
		protected Registry $registry,
		protected DatabaseInterface $database,
		protected SqlFilterParser $filterParser,
		int $defaultLimit = 100,
		int $maxLimit = 1000,
		protected ?CycleDbalFactory $dbalFactory = null,
		protected ?SqlExpressionBuilder $expressions = null
	) {
		parent::__construct($defaultLimit, $maxLimit);
		$this->dataLoader = new RestDataLoader();
		$this->dbalFactory ??= new CycleDbalFactory();
		$this->expressions ??= new SqlExpressionBuilder($this->getDatabase());
		$this->filterParser->setExpressionBuilder($this->expressions);
		$this->filterApplier = new SqlFilterApplier($this->getDatabase(), $this->expressions);
	}

	public function transaction(callable $callback): mixed
	{
		return $this->getDatabase()->transaction($callback);
	}

	public function list(CollectionInterface $collection, array $params = []): array
	{
		$fields = $params['fields'] ?? ['fields' => [], 'relations' => []];
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $fields['fields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $fields['relations']);
		$fieldsForSelect = $fields;
		$fieldsForSelect['fields'] = array_values(array_unique(array_merge(
			$fieldsForSelect['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->newSelectQuery($collection, $fieldsForSelect['fields']);
		$this->applyFilters($query, $collection, $params['filter'] ?? []);
		$this->applySearch($query, $collection, $params['search'] ?? null);

		$meta = [];
		$requestedMeta = $params['meta'] ?? [];
		if (in_array('total_count', $requestedMeta, true)) {
			$meta['total_count'] = $this->getDatabase()->select()
				->from($collection->getTable())
				->count();
		}

		if (in_array('filter_count', $requestedMeta, true)) {
			$meta['filter_count'] = (clone $query)->count();
		}

		$this->applySort($query, $collection, $params['sort'] ?? null);
		$this->applyPagination($query, $params);

		$items = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);

		$visibleFields = $this->getVisibleFields($collection);
		$items = array_map(
			fn(array $item) => array_intersect_key($item, array_flip($visibleFields)),
			$items
		);

		if (!empty($fields['relations'])) {
			$items = $this->loadRelations(
				$collection,
				$items,
				$fields['relations'],
				$params['deep'] ?? []
			);
		}

		if (!empty($internalRelationKeyColumnNames)) {
			$items = array_map(
				fn(array $item) => $this->stripUnrequestedColumnNames($item, $internalRelationKeyColumnNames, $requestedColumnNames),
				$items
			);
		}

		return [
			'items' => $items,
			'meta' => $meta,
		];
	}

	public function get(CollectionInterface $collection, string $id, array $params = []): ?array
	{
		$fields = $params['fields'] ?? ['fields' => [], 'relations' => []];
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $fields['fields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $fields['relations']);
		$fieldsForSelect = $fields;
		$fieldsForSelect['fields'] = array_values(array_unique(array_merge(
			$fieldsForSelect['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->newSelectQuery($collection, $fieldsForSelect['fields']);
		$query->where($this->getPrimaryKeyColumn($collection), $id)->limit(1);
		$rows = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);
		$item = $rows[0] ?? null;

		if ($item === null) {
			return null;
		}

		$item = array_intersect_key($item, array_flip($this->getVisibleFields($collection)));

		if (!empty($fields['relations'])) {
			$items = $this->loadRelations(
				$collection,
				[$item],
				$fields['relations'],
				$params['deep'] ?? []
			);
			$item = $items[0];
		}

		if (!empty($internalRelationKeyColumnNames)) {
			$item = $this->stripUnrequestedColumnNames($item, $internalRelationKeyColumnNames, $requestedColumnNames);
		}

		return $item;
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		try {
			$input = $this->mapInputToColumns($collection, $input);
			$lastId = $this->getDatabase()->insert($collection->getTable())
				->values($input)
				->run();

			return $this->get($collection, (string) $lastId) ?? [];
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function update(CollectionInterface $collection, string $id, array $input): ?array
	{
		try {
			if ($input === []) {
				return $this->get($collection, $id);
			}

			$this->getDatabase()->update($collection->getTable())
				->values($this->mapInputToColumns($collection, $input))
				->where($this->getPrimaryKeyColumn($collection), $id)
				->run();

			return $this->get($collection, $id);
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function delete(CollectionInterface $collection, string $id): bool
	{
		try {
			if ($this->get($collection, $id) === null) {
				return false;
			}

			return $this->getDatabase()->delete($collection->getTable())
				->where($this->getPrimaryKeyColumn($collection), $id)
				->run() > 0;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function connectManyToMany(CollectionInterface $collection, string $parentId, string $relationName, mixed $targetId): void
	{
		if (!$collection->relations->has($relationName)) {
			return;
		}

		$relation = $collection->relations->get($relationName);
		if (!$relation->isJunction()) {
			return;
		}

		$through = $relation->through;
		$this->insertJunctionRow(
			$through->getCollection(),
			(string) $through->getInnerKey(),
			(string) $through->getOuterKey(),
			$parentId,
			$targetId
		);
	}

	public function disconnectManyToMany(CollectionInterface $collection, string $parentId, string $relationName, mixed $targetId): void
	{
		if (!$collection->relations->has($relationName)) {
			return;
		}

		$relation = $collection->relations->get($relationName);
		if (!$relation->isJunction()) {
			return;
		}

		$through = $relation->through;
		$this->getDatabase()->delete($through->getCollection())
			->where($through->getInnerKey(), $parentId)
			->where($through->getOuterKey(), $targetId)
			->run();
	}

	protected function insertJunctionRow(string $table, string $innerCol, string $outerCol, mixed $parentId, mixed $targetId): void
	{
		try {
			$this->getDatabase()->insert($table)->values([
				$innerCol => $parentId,
				$outerCol => $targetId,
			])->run();
		} catch (\Throwable $e) {
			$message = $e->getMessage();
			if (!str_contains($message, 'UNIQUE') && !str_contains($message, 'Duplicate')) {
				throw $e;
			}
		}
	}

	public function aggregate(CollectionInterface $collection, array $params = []): array
	{
		$aggregates = $params['aggregate'] ?? [];
		$groupBy = $this->parseArrayValue($params['groupBy'] ?? []);

		if ($aggregates === []) {
			return [];
		}

		$query = $this->getDatabase()->select()->from($collection->getTable());
		$this->applyFilters($query, $collection, $params['filter'] ?? []);
		$this->applySearch($query, $collection, $params['search'] ?? null);

		$selectExpressions = [];

		$groupAliases = $this->getGroupByAliases($groupBy);
		foreach ($groupBy as $field) {
			$expression = $this->expressions->queryField($collection, (string) $field, $collection->getTable());
			if ($expression === null) {
				continue;
			}

			$query->groupBy($expression);
			$selectExpression = $this->expressions->selectedField(
				$collection,
				(string) $field,
				$collection->getTable(),
				$groupAliases[$field]
			);
			if ($selectExpression !== null) {
				$selectExpressions[] = $selectExpression;
			}
		}

		foreach ([
			'count' => 'COUNT',
			'sum' => 'SUM',
			'avg' => 'AVG',
			'min' => 'MIN',
			'max' => 'MAX',
			'countDistinct' => 'COUNT',
			'sumDistinct' => 'SUM',
			'avgDistinct' => 'AVG',
		] as $allowedFunction => $sqlFunction) {
			if (!array_key_exists($allowedFunction, $aggregates)) {
				continue;
			}

			$fieldList = is_array($aggregates[$allowedFunction]) ? $aggregates[$allowedFunction] : [$aggregates[$allowedFunction]];
			foreach ($fieldList as $field) {
				$alias = $this->aggregateAlias($allowedFunction, (string) $field);
				$expression = $this->expressions->aggregate(
					$sqlFunction,
					$collection,
					(string) $field,
					$collection->getTable(),
					$alias,
					str_ends_with($allowedFunction, 'Distinct')
				);
				if ($expression !== null) {
					$selectExpressions[] = $expression;
				}
			}
		}

		if ($selectExpressions === []) {
			return [];
		}

		$query->columns($selectExpressions);
		$rows = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);

		return $this->formatAggregateResult($rows, $aggregates, $groupBy);
	}

	public function clearCache(): void
	{
		$this->dataLoader->clear();
		$this->dbal = null;
	}

	protected function newSelectQuery(CollectionInterface $collection, array $fieldNames = []): \Cycle\Database\Query\SelectQuery
	{
		$query = $this->getDatabase()->select()->from($collection->getTable());
		$columns = $this->buildSelectColumnNames($collection, $fieldNames);

		if ($columns !== null) {
			$query->columns($columns);
		}

		return $query;
	}

	protected function buildSelectColumnNames(CollectionInterface $collection, ?array $fieldNames): ?array
	{
		if ($fieldNames === null || $fieldNames === []) {
			return null;
		}

		$columnNames = [];
		foreach ($fieldNames as $fieldName) {
			if ($collection->fields->has($fieldName)) {
				$columnNames[] = $collection->fields->get($fieldName)->getColumn();
			}
		}

		return $columnNames === [] ? null : $columnNames;
	}

	protected function applyFilters(object $query, CollectionInterface $collection, array $filters): void
	{
		if ($filters === []) {
			return;
		}

		$this->filterApplier->apply($query, $collection, $filters);
	}

	protected function applySearch(object $query, CollectionInterface $collection, ?string $search): void
	{
		if ($search === null || $search === '') {
			return;
		}

		$stringFields = $this->getStringFields($collection);
		if ($stringFields === []) {
			return;
		}

		$query->where(function ($select) use ($collection, $stringFields, $search) {
			foreach ($stringFields as $index => $fieldName) {
				$method = $index === 0 ? 'where' : 'orWhere';
				$select->{$method}($collection->fields->get($fieldName)->getColumn(), 'like', '%' . $search . '%');
			}
		});
	}

	protected function applySort(object $query, CollectionInterface $collection, ?string $sort): void
	{
		if ($sort === null || $sort === '') {
			return;
		}

		foreach (array_map('trim', explode(',', $sort)) as $part) {
			if ($part === '') {
				continue;
			}

			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}

			$expression = $this->expressions->queryField($collection, $part, $collection->getTable());
			if ($expression !== null) {
				$query->orderBy($expression, $direction);
			}
		}
	}

	protected function applyPagination(object $query, array $params): void
	{
		$limit = isset($params['limit']) ? (int) $params['limit'] : $this->defaultLimit;
		$limit = min($limit, $this->maxLimit);
		if ($limit < 1) {
			$limit = $this->defaultLimit;
		}

		$offset = null;
		if (isset($params['offset'])) {
			$offset = (int) $params['offset'];
		} elseif (isset($params['page'])) {
			$offset = (max(1, (int) $params['page']) - 1) * $limit;
		}

		$query->limit($limit);
		if ($offset !== null && $offset > 0) {
			$query->offset($offset);
		}
	}

	protected function loadRelations(CollectionInterface $collection, array $items, array $relationFields, array $deepParams = []): array
	{
		if ($items === [] || $relationFields === []) {
			return $items;
		}

		$database = $this->getDatabase();

		foreach ($relationFields as $relationName => $relParsed) {
			$targetRelationName = $relParsed['_relation'] ?? $relationName;
			if (!$collection->relations->has($targetRelationName)) {
				continue;
			}

			$relation = $collection->relations->get($targetRelationName);
			$targetCollection = $this->registry->getCollection($relation->getCollection());
			if ($targetCollection === null) {
				continue;
			}

			$innerCol = $this->fieldOrColumnToColumn($collection, $relation->getInnerKey());
			$outerCol = $this->fieldOrColumnToColumn($targetCollection, $relation->getOuterKey());

			$parentIds = [];
			foreach ($items as $item) {
				$val = $item[$innerCol] ?? null;
				if ($val !== null && !in_array($val, $parentIds, true)) {
					$parentIds[] = $val;
				}
			}

			if ($parentIds === []) {
				foreach ($items as &$item) {
					$item[$relationName] = $relation->getCardinality() === 'single' ? null : [];
				}
				unset($item);
				continue;
			}

			if (!empty($relParsed['fields'])) {
				$relColumns = [];
				foreach ($relParsed['fields'] as $fieldName) {
					if ($targetCollection->fields->has($fieldName)) {
						$relColumns[] = $targetCollection->fields->get($fieldName)->getColumn();
					}
				}
				$requestedRelColumnNames = $relColumns;
				$internalRelKeyColumnNames = [$outerCol];
				foreach ($this->getRelationKeyColumnNames($targetCollection, $relParsed['relations'] ?? []) as $nestedKeyColumn) {
					if (!in_array($nestedKeyColumn, $internalRelKeyColumnNames, true)) {
						$internalRelKeyColumnNames[] = $nestedKeyColumn;
					}
				}
				foreach ($internalRelKeyColumnNames as $internalRelColumn) {
					if (!in_array($internalRelColumn, $relColumns, true)) {
						$relColumns[] = $internalRelColumn;
					}
				}
			} else {
				$relColumns = null;
				$requestedRelColumnNames = $this->getVisibleFields($targetCollection);
				$internalRelKeyColumnNames = [];
			}

			if (!in_array($outerCol, $internalRelKeyColumnNames, true)) {
				$internalRelKeyColumnNames[] = $outerCol;
			}
			if ($relColumns !== null && !in_array($outerCol, $relColumns, true)) {
				$relColumns[] = $outerCol;
			}

			$relDeep = $deepParams[$relationName] ?? [];
			$relOrderBy = !empty($relDeep['_sort']) ? $this->buildOrderByExpressions($targetCollection, $relDeep['_sort']) : [];
			$relLimit = isset($relDeep['_limit']) ? (int) $relDeep['_limit'] : null;
			$relOffset = isset($relDeep['_offset']) ? (int) $relDeep['_offset'] : null;
			$relFilters = !empty($relDeep['_filter']) && is_array($relDeep['_filter']) ? $relDeep['_filter'] : [];

			if ($relation->isJunction() && $relation instanceof M2MRelation) {
				$items = $this->loadM2MRelation(
					$relation,
					$targetCollection,
					$items,
					$innerCol,
					$relationName,
					$relColumns,
					$relFilters,
					$relOrderBy,
					$relLimit,
					$relOffset,
					$database
				);
				continue;
			}

			$grouped = $this->dataLoader->loadBatch(
				$targetCollection->getTable(),
				$outerCol,
				$parentIds,
				$database,
				$relColumns,
				$targetCollection,
				$relFilters,
				$this->filterApplier,
				$relOrderBy,
				$relLimit,
				$relOffset
			);

			$visibleFields = $this->getVisibleFields($targetCollection);
			$isSingle = $relation->getCardinality() === 'single';

			foreach ($items as &$item) {
				$parentVal = $item[$innerCol] ?? null;
				$related = array_map(
					fn(array $row) => array_intersect_key($row, array_flip($visibleFields)),
					$grouped[$parentVal] ?? []
				);

				$item[$relationName] = $isSingle ? ($related[0] ?? null) : $related;
			}
			unset($item);

			if (!empty($relParsed['relations'])) {
				foreach ($items as &$item) {
					$relData = $item[$relationName] ?? null;
					if ($relData === null) {
						continue;
					}

					$item[$relationName] = $isSingle
						? ($this->loadRelations($targetCollection, [$relData], $relParsed['relations'])[0] ?? null)
						: $this->loadRelations($targetCollection, $relData, $relParsed['relations']);
				}
				unset($item);
			}

			if ($internalRelKeyColumnNames !== []) {
				foreach ($items as &$item) {
					$relData = $item[$relationName] ?? null;
					if ($relData === null) {
						continue;
					}

					$item[$relationName] = $isSingle
						? $this->stripUnrequestedColumnNames($relData, $internalRelKeyColumnNames, $requestedRelColumnNames)
						: array_map(
							fn(array $row) => $this->stripUnrequestedColumnNames($row, $internalRelKeyColumnNames, $requestedRelColumnNames),
							$relData
						);
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
		array $relFilters,
		array $relOrderBy,
		?int $relLimit,
		?int $relOffset,
		CycleDatabaseInterface $database
	): array {
		$through = $relation->through;
		$junctionTable = $through->getCollection();
		$throughInnerKey = (string) $through->getInnerKey();
		$throughOuterKey = (string) $through->getOuterKey();
		$targetPkColumn = $this->getPrimaryKeyColumn($targetCollection);

		$parentIds = [];
		foreach ($items as $item) {
			$val = $item[$innerKey] ?? null;
			if ($val !== null && !in_array($val, $parentIds, true)) {
				$parentIds[] = $val;
			}
		}

		if ($parentIds === []) {
			foreach ($items as &$item) {
				$item[$relationName] = [];
			}
			unset($item);

			return $items;
		}

		$junctionRows = $database->select([$throughInnerKey, $throughOuterKey])
			->from($junctionTable)
			->where($throughInnerKey, 'IN', $parentIds)
			->fetchAll(CycleStatementInterface::FETCH_ASSOC);

		$parentToTargets = [];
		$allTargetIds = [];
		foreach ($junctionRows as $row) {
			$parentToTargets[$row[$throughInnerKey]][] = $row[$throughOuterKey];
			if (!in_array($row[$throughOuterKey], $allTargetIds, true)) {
				$allTargetIds[] = $row[$throughOuterKey];
			}
		}

		$targetItems = [];
		if ($allTargetIds !== []) {
			$query = $database->select()->from($targetCollection->getTable())
				->where($targetPkColumn, 'IN', $allTargetIds);

			if ($relColumns !== null) {
				$query->columns($relColumns);
			}
			if ($relFilters !== []) {
				$this->filterApplier->apply($query, $targetCollection, $relFilters);
			}
			foreach ($relOrderBy as $order) {
				if (
					!is_array($order)
					|| !isset($order['expression'])
					|| !$order['expression'] instanceof \Cycle\Database\Injection\FragmentInterface
				) {
					continue;
				}

				$query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
			}

			foreach ($query->fetchAll(CycleStatementInterface::FETCH_ASSOC) as $row) {
				$targetItems[$row[$targetPkColumn]] = $row;
			}
		}

		$visibleFields = $this->getVisibleFields($targetCollection);
		foreach ($items as &$item) {
			$parentVal = $item[$innerKey] ?? null;
			$related = [];

			foreach ($parentToTargets[$parentVal] ?? [] as $targetId) {
				if (isset($targetItems[$targetId])) {
					$related[] = array_intersect_key($targetItems[$targetId], array_flip($visibleFields));
				}
			}

			if ($relLimit !== null || $relOffset !== null) {
				$related = array_slice($related, $relOffset ?? 0, $relLimit);
			}

			$item[$relationName] = $related;
		}
		unset($item);

		return $items;
	}

	protected function buildOrderByExpressions(CollectionInterface $collection, string $sort): array
	{
		$orders = [];
		foreach (array_map('trim', explode(',', $sort)) as $part) {
			if ($part === '') {
				continue;
			}

			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}

			$expression = $this->expressions->queryField($collection, $part, $collection->getTable());
			if ($expression !== null) {
				$orders[] = [
					'expression' => $expression,
					'direction' => $direction,
				];
			}
		}

		return $orders;
	}

	protected function getConnection(): PDO
	{
		$connection = $this->database->getConnection();
		if (!$connection instanceof PDO) {
			throw new \RuntimeException('SQL REST resolver requires a PDO connection');
		}

		return $connection;
	}

	protected function getDatabase(): CycleDatabaseInterface
	{
		if ($this->dbal === null) {
			$this->dbal = $this->dbalFactory->fromPdo($this->getConnection(), $this->database->getName());
		}

		return $this->dbal;
	}

	protected function mapInputToColumns(CollectionInterface $collection, array $input): array
	{
		$mapped = [];
		foreach ($input as $fieldName => $value) {
			$mapped[$this->fieldOrColumnToColumn($collection, (string) $fieldName)] = $value;
		}

		return $mapped;
	}

	protected function fieldNamesToColumnNames(CollectionInterface $collection, array $fieldNames): array
	{
		$columnNames = [];
		foreach ($fieldNames as $fieldName) {
			$columnNames[] = $this->fieldOrColumnToColumn($collection, (string) $fieldName);
		}

		return array_values(array_unique($columnNames));
	}

	protected function columnNamesToFieldNames(CollectionInterface $collection, array $columnNames): array
	{
		$fieldNames = [];
		foreach ($columnNames as $columnName) {
			$fieldNames[] = $collection->fields->hasColumn($columnName)
				? $collection->fields->getKeyByColumnName($columnName)
				: $columnName;
		}

		return array_values(array_unique($fieldNames));
	}

	protected function getRelationKeyColumnNames(CollectionInterface $collection, array $relationFields): array
	{
		$columnNames = [];
		foreach ($relationFields as $relationName => $relationData) {
			$targetRelationName = is_array($relationData) ? ($relationData['_relation'] ?? $relationName) : $relationName;
			if ($collection->relations->has($targetRelationName)) {
				$columnNames[] = $this->fieldOrColumnToColumn($collection, $collection->relations->get($targetRelationName)->getInnerKey());
			}
		}

		return array_values(array_unique($columnNames));
	}

	protected function fieldOrColumnToColumn(CollectionInterface $collection, string $fieldOrColumn): string
	{
		return $collection->fields->has($fieldOrColumn)
			? $collection->fields->get($fieldOrColumn)->getColumn()
			: $fieldOrColumn;
	}

	protected function stripUnrequestedColumnNames(array $item, array $internalColumnNames, array $requestedColumnNames): array
	{
		foreach ($internalColumnNames as $column) {
			if (!in_array($column, $requestedColumnNames, true)) {
				unset($item[$column]);
			}
		}

		return $item;
	}

	protected function convertDatabaseError(\Throwable $e, CollectionInterface $collection): RestApiError
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
