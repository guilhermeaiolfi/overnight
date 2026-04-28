<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Cycle;

use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\QueryParameters;
use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Transaction\Runner;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\AbstractRestResolver;

class CycleRestResolver extends AbstractRestResolver
{
	protected ?EntityManager $entityManager = null;
	protected bool $inTransaction = false;

	public function __construct(
		protected ORMInterface $orm,
		Registry $registry,
		int $defaultLimit = 100,
		int $maxLimit = 1000
	) {
		parent::__construct($registry, $defaultLimit, $maxLimit);
	}

	// -------------------------------------------------------------------------
	// Transaction hooks
	// -------------------------------------------------------------------------
	// For nested operations (createWithRelations), we open an outer DBAL
	// transaction. Each create/update/delete then uses Runner::outerTransaction()
	// so all operations participate in the same transaction.
	// For standalone calls, create/update/delete use the default runner
	// (EntityManager handles its own transaction).

	protected function beginTransaction(): void
	{
		$this->getDatabase()->begin();
		$this->inTransaction = true;
	}

	protected function commitTransaction(): void
	{
		$this->getDatabase()->commit();
		$this->inTransaction = false;
	}

	protected function rollbackTransaction(): void
	{
		$this->getDatabase()->rollback();
		$this->inTransaction = false;
	}

	// -------------------------------------------------------------------------
	// CRUD operations
	// -------------------------------------------------------------------------

	public function list(CollectionInterface $collection, array $params = []): array
	{
		$role = $collection->getName();
		$select = new Select($this->orm, $role);

		// Field selection (pre-parsed by middleware)
		$fields = $params['fields'] ?? ['columns' => [], 'relations' => []];

		// Apply Directus-style filters via Cycle's query builder
		$this->applyFilters($select, $collection, $params['filter'] ?? []);

		// Apply search via LIKE on all string fields
		if (!empty($params['search'])) {
			$this->applySearch($select, $collection, $params['search']);
		}

		// Apply sorting
		if (!empty($params['sort'])) {
			$this->applySort($select, $collection, $params['sort']);
		}

		// Eager-load relations
		foreach ($fields['relations'] as $relationName => $relParsed) {
			if ($collection->relations->has($relationName)) {
				$select->load($relationName);
			}
		}

		// Get filtered count before pagination
		$filteredCount = $select->count();

		// Pagination
		$limit = isset($params['limit']) ? min((int) $params['limit'], $this->maxLimit) : $this->defaultLimit;
		$offset = 0;

		if (isset($params['offset'])) {
			$offset = (int) $params['offset'];
		} elseif (isset($params['page'])) {
			$offset = (max(1, (int) $params['page']) - 1) * $limit;
		}

		$select->limit($limit);
		if ($offset > 0) {
			$select->offset($offset);
		}

		$entities = $select->fetchAll();
		$items = iterator_to_array($entities);

		// Convert entities to arrays, filter to requested columns, exclude hidden fields
		$visibleFields = $this->getVisibleFields($collection);
		$requestedColumns = $fields['columns'];
		$items = array_map(fn($entity) => $this->entityToArray($entity, $visibleFields, $requestedColumns), $items);

		// Build meta
		$meta = [];
		$requestedMeta = $params['meta'] ?? [];

		if (in_array('total_count', $requestedMeta, true)) {
			$meta['total_count'] = (new Select($this->orm, $role))->count();
		}

		if (in_array('filter_count', $requestedMeta, true)) {
			$meta['filter_count'] = $filteredCount;
		}

		return [
			'items' => $items,
			'meta' => $meta,
		];
	}

	public function get(CollectionInterface $collection, string $id, array $params = []): ?array
	{
		$role = $collection->getName();
		$select = new Select($this->orm, $role);
		$select->wherePK($id);

		// Field selection (pre-parsed by middleware)
		$fields = $params['fields'] ?? ['columns' => [], 'relations' => []];

		// Eager-load relations
		foreach ($fields['relations'] as $relationName => $relParsed) {
			if ($collection->relations->has($relationName)) {
				$select->load($relationName);
			}
		}

		$entity = $select->fetchOne();

		if ($entity === null) {
			return null;
		}

		$visibleFields = $this->getVisibleFields($collection);
		$requestedColumns = $fields['columns'];

		return $this->entityToArray($entity, $visibleFields, $requestedColumns);
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		try {
			$entityClass = $collection->getEntity();
			$entity = new $entityClass();

			foreach ($input as $key => $value) {
				$entity->{$key} = $value;
			}

			$em = $this->getEntityManager();
			$em->persist($entity, cascade: false);
			$this->runEntityManager($em);

			$visibleFields = $this->getVisibleFields($collection);

			return $this->entityToArray($entity, $visibleFields);
		} catch (\Throwable $e) {
			throw new RestApiError('Failed to create record: ' . $e->getMessage(), 'DATABASE_ERROR', null, 500, $e);
		}
	}

	public function update(CollectionInterface $collection, string $id, array $input): ?array
	{
		try {
			$role = $collection->getName();
			$entity = $this->orm->getRepository($role)->findByPK($id);

			if ($entity === null) {
				return null;
			}

			foreach ($input as $key => $value) {
				$entity->{$key} = $value;
			}

			$em = $this->getEntityManager();
			$em->persist($entity, cascade: false);
			$this->runEntityManager($em);

			$visibleFields = $this->getVisibleFields($collection);

			return $this->entityToArray($entity, $visibleFields);
		} catch (\Throwable $e) {
			throw new RestApiError('Failed to update record: ' . $e->getMessage(), 'DATABASE_ERROR', null, 500, $e);
		}
	}

	public function delete(CollectionInterface $collection, string $id): bool
	{
		try {
			$role = $collection->getName();
			$entity = $this->orm->getRepository($role)->findByPK($id);

			if ($entity === null) {
				return false;
			}

			$em = $this->getEntityManager();
			$em->delete($entity, cascade: false);
			$this->runEntityManager($em);

			return true;
		} catch (\Throwable $e) {
			throw new RestApiError('Failed to delete record: ' . $e->getMessage(), 'DATABASE_ERROR', null, 500, $e);
		}
	}

	// -------------------------------------------------------------------------
	// M2M — via Cycle DBAL
	// -------------------------------------------------------------------------

	public function handleM2M(CollectionInterface $collection, string $parentId, M2MRelation $relation, array $operations): void
	{
		$database = $this->getDatabase();

		$through = $relation->through;
		$junctionTable = $through->getCollection();
		$throughInnerKey = $through->getInnerKey();
		$throughOuterKey = $through->getOuterKey();

		$targetCollection = $this->registry->getCollection($relation->getCollection());

		// Create: insert target records, then connect
		if (!empty($operations['create']) && $targetCollection !== null) {
			$targetPkColumn = $this->getPrimaryKeyColumn($targetCollection);
			foreach ($operations['create'] as $createInput) {
				if (!is_array($createInput)) {
					continue;
				}
				$created = $this->create($targetCollection, $createInput);
				$targetId = $created[$targetPkColumn] ?? null;

				if ($targetId !== null) {
					$database->insert($junctionTable)->values([
						$throughInnerKey => $parentId,
						$throughOuterKey => $targetId,
					])->run();
				}
			}
		}

		// Connect — check existence first to avoid duplicates
		if (!empty($operations['connect'])) {
			foreach ($operations['connect'] as $targetId) {
				$existing = $database->select()
					->from($junctionTable)
					->where($throughInnerKey, $parentId)
					->where($throughOuterKey, $targetId)
					->count();

				if ($existing === 0) {
					$database->insert($junctionTable)->values([
						$throughInnerKey => $parentId,
						$throughOuterKey => $targetId,
					])->run();
				}
			}
		}

		// Disconnect
		if (!empty($operations['disconnect'])) {
			foreach ($operations['disconnect'] as $targetId) {
				$database->delete($junctionTable)
					->where($throughInnerKey, $parentId)
					->where($throughOuterKey, $targetId)
					->run();
			}
		}
	}

	// -------------------------------------------------------------------------
	// Aggregation — via Cycle DBAL
	// -------------------------------------------------------------------------

	public function aggregate(CollectionInterface $collection, array $params = []): array
	{
		$database = $this->getDatabase();
		$table = $collection->getTable();

		$aggregates = $params['aggregate'] ?? [];
		$groupBy = $params['groupBy'] ?? [];

		if (empty($aggregates)) {
			return [];
		}

		$query = $database->select()->from(sprintf('%s AS %s', $table, $table));

		// Apply filters
		$this->applyDBALFilters($query, $collection, $params['filter'] ?? []);

		// Build aggregate columns
		$allowedFunctions = ['count', 'sum', 'avg', 'min', 'max'];
		$selectExpressions = [];

		foreach ($groupBy as $field) {
			if ($collection->fields->has($field)) {
				$col = $collection->fields->get($field)->getColumn();
				$selectExpressions[] = new Fragment(sprintf('%s AS %s', $table . '.' . $col, $field));
				$query->groupBy($table . '.' . $col);
			}
		}

		foreach ($aggregates as $func => $fields) {
			$func = strtolower($func);
			if (!in_array($func, $allowedFunctions, true)) {
				continue;
			}

			$fieldList = is_array($fields) ? $fields : [$fields];
			foreach ($fieldList as $field) {
				if ($field === '*' && $func === 'count') {
					$selectExpressions[] = new Fragment('COUNT(*) AS count_*');
				} elseif ($collection->fields->has($field)) {
					$col = $collection->fields->get($field)->getColumn();
					$alias = $func . '_' . $field;
					$selectExpressions[] = new Fragment(strtoupper($func) . '(' . $table . '.' . $col . ") AS {$alias}");
				}
			}
		}

		if (empty($selectExpressions)) {
			return [];
		}

		$query->columns($selectExpressions);
		$rows = $query->fetchAll();

		return $this->formatAggregateResult($rows, $aggregates, $groupBy);
	}

	// -------------------------------------------------------------------------
	// Cache
	// -------------------------------------------------------------------------

	public function clearCache(): void
	{
		// Clean the ORM Heap to prevent stale entities between requests
		$this->orm->getHeap()->clean();
		$this->entityManager = null;
	}

	// -------------------------------------------------------------------------
	// Query building helpers
	// -------------------------------------------------------------------------

	/**
	 * Apply Directus-style filters to a Cycle Select query.
	 * Supports: _eq, _neq, _lt, _lte, _gt, _gte, _in, _nin, _null, _nnull,
	 * _contains, _starts_with, _ends_with, _between.
	 */
	protected function applyFilters(Select $select, CollectionInterface $collection, array $filters): void
	{
		if ($filters === []) {
			return;
		}

		$normalized = $this->normalizeFilters($filters);
		$requiresDistinct = $this->applyFilterGroup($select, $collection, $normalized, null);

		if ($requiresDistinct) {
			$select->distinct();
		}
	}

	protected function applyFilterGroup(
		Select|Select\QueryBuilder $builder,
		CollectionInterface $collection,
		array $filters,
		?string $pathPrefix
	): bool {
		$requiresDistinct = false;

		foreach ($filters as $field => $conditions) {
			if ($field === '_or' && is_array($conditions)) {
				$branchDistinct = false;
				$builder->where(function (Select\QueryBuilder $qb) use ($collection, $conditions, $pathPrefix, &$branchDistinct) {
					foreach ($conditions as $index => $group) {
						if (!is_array($group)) {
							continue;
						}

						$method = $index === 0 ? 'where' : 'orWhere';
						$qb->{$method}(function (Select\QueryBuilder $inner) use ($collection, $group, $pathPrefix, &$branchDistinct) {
							$branchDistinct = $this->applyFilterGroup($inner, $collection, $this->normalizeFilters($group), $pathPrefix) || $branchDistinct;
						});
					}
				});
				$requiresDistinct = $requiresDistinct || $branchDistinct;
				continue;
			}

			if ($field === '_and' && is_array($conditions)) {
				$branchDistinct = false;
				$builder->where(function (Select\QueryBuilder $qb) use ($collection, $conditions, $pathPrefix, &$branchDistinct) {
					foreach ($conditions as $group) {
						if (!is_array($group)) {
							continue;
						}

						$qb->where(function (Select\QueryBuilder $inner) use ($collection, $group, $pathPrefix, &$branchDistinct) {
							$branchDistinct = $this->applyFilterGroup($inner, $collection, $this->normalizeFilters($group), $pathPrefix) || $branchDistinct;
						});
					}
				});
				$requiresDistinct = $requiresDistinct || $branchDistinct;
				continue;
			}

			if ($collection->relations->has($field) && is_array($conditions)) {
				$requiresDistinct = $this->applyRelationFilter($builder, $collection, $field, $conditions, $pathPrefix) || $requiresDistinct;
				continue;
			}

			if (is_array($conditions) && $this->isOperatorArray($conditions)) {
				foreach ($conditions as $operator => $value) {
					$this->applyFilterCondition($builder, $collection, $field, $operator, $value, $pathPrefix);
				}
			}
		}

		return $requiresDistinct;
	}

	protected function applyRelationFilter(
		Select|Select\QueryBuilder $builder,
		CollectionInterface $collection,
		string $relationName,
		array $filters,
		?string $pathPrefix
	): bool {
		$relation = $collection->relations->get($relationName);
		$targetCollection = $this->registry->getCollection($relation->getCollection());

		if ($targetCollection === null) {
			return false;
		}

		$relationPath = $pathPrefix === null ? $relationName : $pathPrefix . '.' . $relationName;
		$builder->with($relationPath);

		$requiresDistinct = $relation->getCardinality() === 'many' || $relation->isJunction();

		$requiresDistinct = $this->applyFilterGroup($builder, $targetCollection, $this->normalizeFilters($filters), $relationPath) || $requiresDistinct;

		if ($relation->getWhere() !== []) {
			$requiresDistinct = $this->applyFilterGroup($builder, $targetCollection, $this->normalizeFilters($relation->getWhere()), $relationPath) || $requiresDistinct;
		}

		return $requiresDistinct;
	}

	protected function applyFilterCondition(
		Select|Select\QueryBuilder $builder,
		CollectionInterface $collection,
		string $field,
		string $operator,
		mixed $value,
		?string $pathPrefix
	): void {
		if (!$collection->fields->has($field)) {
			return;
		}

		$identifier = $pathPrefix === null ? $field : $pathPrefix . '.' . $field;

		match ($operator) {
			'_eq' => $builder->where($identifier, $value),
			'_neq' => $builder->where($identifier, '!=', $value),
			'_lt' => $builder->where($identifier, '<', $value),
			'_lte' => $builder->where($identifier, '<=', $value),
			'_gt' => $builder->where($identifier, '>', $value),
			'_gte' => $builder->where($identifier, '>=', $value),
			'_in' => $builder->where($identifier, 'in', new Parameter($this->parseArrayValue($value))),
			'_nin' => $builder->where($identifier, 'not in', new Parameter($this->parseArrayValue($value))),
			'_null' => $builder->where($identifier, '=', null),
			'_nnull' => $builder->where($identifier, '!=', null),
			'_contains' => $builder->where($identifier, 'like', "%{$value}%"),
			'_ncontains' => $builder->whereNot($identifier, 'like', "%{$value}%"),
			'_starts_with' => $builder->where($identifier, 'like', "{$value}%"),
			'_ends_with' => $builder->where($identifier, 'like', "%{$value}"),
			'_between' => $this->applyBetween($builder, $identifier, $value, false),
			'_nbetween' => $this->applyBetween($builder, $identifier, $value, true),
			'_empty' => $builder->where(function (Select\QueryBuilder $qb) use ($identifier) {
				$qb->where($identifier, '=', null);
				$qb->orWhere($identifier, '');
			}),
			'_nempty' => $builder->where(function (Select\QueryBuilder $qb) use ($identifier) {
				$qb->where($identifier, '!=', null);
				$qb->where($identifier, '!=', '');
			}),
			default => null,
		};
	}

	protected function applyBetween(Select|Select\QueryBuilder $builder, string $identifier, mixed $value, bool $negated): void
	{
		$parts = $this->parseArrayValue($value);
		if (count($parts) !== 2) {
			return;
		}

		$builder->where(
			$identifier,
			$negated ? 'not between' : 'between',
			$parts[0],
			$parts[1]
		);
	}

	/**
	 * Apply search via LIKE on all string fields.
	 */
	protected function applySearch(Select $select, CollectionInterface $collection, string $search): void
	{
		$searchFields = $this->getStringFields($collection);
		if (empty($searchFields)) {
			return;
		}

		$select->where(function (Select\QueryBuilder $qb) use ($collection, $searchFields, $search) {
			foreach ($searchFields as $i => $fieldName) {
				$col = $collection->fields->get($fieldName)->getColumn();
				$method = $i === 0 ? 'where' : 'orWhere';
				$qb->{$method}($col, 'like', "%{$search}%");
			}
		});
	}

	/**
	 * Apply sorting from comma-separated sort string.
	 */
	protected function applySort(Select $select, CollectionInterface $collection, string $sort): void
	{
		$parts = array_map('trim', explode(',', $sort));
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}
			if ($collection->fields->has($part)) {
				$col = $collection->fields->get($part)->getColumn();
				$select->orderBy($col, $direction);
			}
		}
	}

	/**
	 * Apply filters to a Cycle DBAL query (for aggregation).
	 */
	protected function applyDBALFilters($query, CollectionInterface $collection, array $filters): void
	{
		if ($filters === []) {
			return;
		}

		$this->applyDBALFilterGroup($query, $collection, $this->normalizeFilters($filters), $collection->getTable());
	}

	protected function applyDBALFilterGroup(
		$query,
		CollectionInterface $collection,
		array $filters,
		string $tableAlias
	): void {
		foreach ($filters as $field => $conditions) {
			if ($field === '_or' && is_array($conditions)) {
				$query->where(function ($inner) use ($collection, $conditions, $tableAlias) {
					foreach ($conditions as $index => $group) {
						if (!is_array($group)) {
							continue;
						}

						$method = $index === 0 ? 'where' : 'orWhere';
						$inner->{$method}(function ($nested) use ($collection, $group, $tableAlias) {
							$this->applyDBALFilterGroup($nested, $collection, $this->normalizeFilters($group), $tableAlias);
						});
					}
				});
				continue;
			}

			if ($field === '_and' && is_array($conditions)) {
				$query->where(function ($inner) use ($collection, $conditions, $tableAlias) {
					foreach ($conditions as $group) {
						if (!is_array($group)) {
							continue;
						}

						$inner->where(function ($nested) use ($collection, $group, $tableAlias) {
							$this->applyDBALFilterGroup($nested, $collection, $this->normalizeFilters($group), $tableAlias);
						});
					}
				});
				continue;
			}

			if ($collection->relations->has($field) && is_array($conditions)) {
				$this->applyDBALRelationFilter($query, $collection, $field, $conditions, $tableAlias);
				continue;
			}

			if (is_array($conditions) && $this->isOperatorArray($conditions)) {
				foreach ($conditions as $operator => $value) {
					$this->applyDBALFilterCondition($query, $collection, $tableAlias, $field, $operator, $value);
				}
			}
		}
	}

	protected function applyDBALRelationFilter(
		$query,
		CollectionInterface $collection,
		string $relationName,
		array $filters,
		string $tableAlias
	): void {
		$relation = $collection->relations->get($relationName);
		$targetCollection = $this->registry->getCollection($relation->getCollection());

		if ($targetCollection === null) {
			return;
		}

		$targetAlias = $this->buildAlias($tableAlias . '__' . $relationName);
		$parameters = new QueryParameters();

		if ($relation->isJunction() && $relation instanceof M2MRelation) {
			$through = $relation->through;
			$junctionAlias = $this->buildAlias($targetAlias . '__junction');
			$subQuery = $this->getDatabase()->select()
				->from(sprintf('%s AS %s', $through->getCollection(), $junctionAlias))
				->innerJoin($targetCollection->getTable(), $targetAlias)
				->on(
					$junctionAlias . '.' . $through->getOuterKey(),
					$targetAlias . '.' . $this->getPrimaryKeyColumn($targetCollection)
				)
				->columns([new Fragment('1')])
				->where(
					$junctionAlias . '.' . $through->getInnerKey(),
					new Fragment($tableAlias . '.' . $this->fieldOrColumnToColumn($collection, (string) $relation->getInnerKey()))
				);

			$this->applyDBALFilterGroup($subQuery, $targetCollection, $this->normalizeFilters($filters), $targetAlias);
			if ($relation->getWhere() !== []) {
				$this->applyDBALFilterGroup($subQuery, $targetCollection, $this->normalizeFilters($relation->getWhere()), $targetAlias);
			}

			$query->where(new Fragment('EXISTS (' . $subQuery->sqlStatement($parameters) . ')', ...$parameters->getParameters()));
			return;
		}

		$subQuery = $this->getDatabase()->select()
			->from(sprintf('%s AS %s', $targetCollection->getTable(), $targetAlias))
			->columns([new Fragment('1')])
			->where(
				$targetAlias . '.' . $this->fieldOrColumnToColumn($targetCollection, (string) $relation->getOuterKey()),
				new Fragment($tableAlias . '.' . $this->fieldOrColumnToColumn($collection, (string) $relation->getInnerKey()))
			);

		$this->applyDBALFilterGroup($subQuery, $targetCollection, $this->normalizeFilters($filters), $targetAlias);
		if ($relation->getWhere() !== []) {
			$this->applyDBALFilterGroup($subQuery, $targetCollection, $this->normalizeFilters($relation->getWhere()), $targetAlias);
		}

		$query->where(new Fragment('EXISTS (' . $subQuery->sqlStatement($parameters) . ')', ...$parameters->getParameters()));
	}

	protected function applyDBALFilterCondition(
		$query,
		CollectionInterface $collection,
		string $tableAlias,
		string $field,
		string $operator,
		mixed $value
	): void {
		if (!$collection->fields->has($field)) {
			return;
		}

		$identifier = $tableAlias . '.' . $collection->fields->get($field)->getColumn();

		match ($operator) {
			'_eq' => $query->where($identifier, $value),
			'_neq' => $query->where($identifier, '!=', $value),
			'_lt' => $query->where($identifier, '<', $value),
			'_lte' => $query->where($identifier, '<=', $value),
			'_gt' => $query->where($identifier, '>', $value),
			'_gte' => $query->where($identifier, '>=', $value),
			'_in' => $query->where($identifier, 'in', new Parameter($this->parseArrayValue($value))),
			'_nin' => $query->where($identifier, 'not in', new Parameter($this->parseArrayValue($value))),
			'_null' => $query->where($identifier, '=', null),
			'_nnull' => $query->where($identifier, '!=', null),
			'_contains' => $query->where($identifier, 'like', "%{$value}%"),
			'_ncontains' => $query->whereNot($identifier, 'like', "%{$value}%"),
			'_starts_with' => $query->where($identifier, 'like', "{$value}%"),
			'_ends_with' => $query->where($identifier, 'like', "%{$value}"),
			'_between' => $this->applyDBALBetween($query, $identifier, $value, false),
			'_nbetween' => $this->applyDBALBetween($query, $identifier, $value, true),
			'_empty' => $query->where(function ($inner) use ($identifier) {
				$inner->where($identifier, '=', null);
				$inner->orWhere($identifier, '');
			}),
			'_nempty' => $query->where(function ($inner) use ($identifier) {
				$inner->where($identifier, '!=', null);
				$inner->where($identifier, '!=', '');
			}),
			default => null,
		};
	}

	protected function applyDBALBetween($query, string $identifier, mixed $value, bool $negated): void
	{
		$parts = $this->parseArrayValue($value);
		if (count($parts) !== 2) {
			return;
		}

		$query->where($identifier, $negated ? 'not between' : 'between', $parts[0], $parts[1]);
	}

	// -------------------------------------------------------------------------
	// Entity conversion
	// -------------------------------------------------------------------------

	/**
	 * Convert an entity to an associative array.
	 * Filters by visible fields (hidden exclusion) and requested columns (field selection).
	 */
	protected function entityToArray(object $entity, array $visibleFields, ?array $requestedColumns = null): array
	{
		$data = [];

		// Determine which fields to include: intersection of visible and requested
		$allowedFields = $visibleFields;
		if ($requestedColumns !== null && !empty($requestedColumns)) {
			$allowedFields = array_intersect($visibleFields, $requestedColumns);
		}

		if ($entity instanceof \stdClass) {
			foreach ((array) $entity as $key => $value) {
				if (in_array($key, $allowedFields, true)) {
					$data[$key] = $this->normalizeValue($value);
				}
			}
			return $data;
		}

		// For typed entities, use reflection
		$ref = new \ReflectionObject($entity);
		foreach ($ref->getProperties() as $prop) {
			$prop->setAccessible(true);
			$name = $prop->getName();
			if (in_array($name, $allowedFields, true)) {
				$data[$name] = $this->normalizeValue($prop->getValue($entity));
			}
		}

		// Dynamic properties
		foreach (get_object_vars($entity) as $key => $value) {
			if (in_array($key, $allowedFields, true) && !isset($data[$key])) {
				$data[$key] = $this->normalizeValue($value);
			}
		}

		return $data;
	}

	/**
	 * Normalize values for JSON output — convert Cycle proxy objects, collections, etc.
	 */
	protected function normalizeValue(mixed $value): mixed
	{
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		if (is_iterable($value) && !is_array($value)) {
			return iterator_to_array($value);
		}

		return $value;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function getEntityManager(): EntityManager
	{
		if ($this->entityManager === null) {
			$this->entityManager = new EntityManager($this->orm);
		}
		return $this->entityManager;
	}

	/**
	 * Run the EntityManager. Uses Runner::outerTransaction() when inside
	 * a transaction (nested operations), default runner otherwise.
	 */
	protected function runEntityManager(EntityManager $em): void
	{
		if ($this->inTransaction) {
			$em->run(runner: Runner::outerTransaction());
		} else {
			$em->run();
		}
	}

	/**
	 * Get the Cycle DBAL database instance for direct queries (M2M, aggregation).
	 */
	protected function getDatabase(): \Cycle\Database\DatabaseInterface
	{
		return $this->orm->getFactory()->database();
	}

	protected function buildScope(CollectionInterface $collection, array $filters): array
	{
		$scope = [];

		foreach ($filters as $field => $conditions) {
			if (!$collection->fields->has($field)) {
				continue;
			}

			if (is_array($conditions) && isset($conditions['_eq'])) {
				$scope[$collection->fields->get($field)->getColumn()] = $conditions['_eq'];
			} elseif (!is_array($conditions)) {
				$scope[$collection->fields->get($field)->getColumn()] = $conditions;
			}
		}

		return $scope;
	}

	protected function normalizeFilters(array $filters): array
	{
		$normalized = [];

		foreach ($filters as $key => $value) {
			if (($key === '_and' || $key === '_or') && is_array($value)) {
				$normalized[$key] = array_map(
					fn(mixed $group) => is_array($group) ? $this->normalizeFilters($group) : $group,
					$value
				);
				continue;
			}

			if (is_string($key) && str_contains($key, '.')) {
				$this->mergeNestedFilter($normalized, explode('.', $key), $value);
				continue;
			}

			$normalized[$key] = $value;
		}

		return $normalized;
	}

	protected function mergeNestedFilter(array &$target, array $segments, mixed $value): void
	{
		$segment = array_shift($segments);
		if ($segment === null) {
			return;
		}

		if ($segments === []) {
			if (isset($target[$segment]) && is_array($target[$segment]) && is_array($value)) {
				$target[$segment] = array_replace_recursive($target[$segment], $value);
				return;
			}

			$target[$segment] = $value;
			return;
		}

		if (!isset($target[$segment]) || !is_array($target[$segment])) {
			$target[$segment] = [];
		}

		$this->mergeNestedFilter($target[$segment], $segments, $value);
	}

	protected function isOperatorArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		foreach (array_keys($value) as $key) {
			if (!is_string($key) || !str_starts_with($key, '_')) {
				return false;
			}
		}

		return true;
	}

	protected function buildAlias(string $value): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $value);
	}

	protected function fieldOrColumnToColumn(CollectionInterface $collection, string $fieldOrColumn): string
	{
		if ($collection->fields->has($fieldOrColumn)) {
			return $collection->fields->get($fieldOrColumn)->getColumn();
		}

		return $fieldOrColumn;
	}
}
