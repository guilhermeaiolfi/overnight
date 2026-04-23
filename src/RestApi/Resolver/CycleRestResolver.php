<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use Cycle\Database\Injection\Parameter;
use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Transaction\Runner;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;

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

		$query = $database->select()->from($table);

		// Apply filters
		$this->applyDBALFilters($query, $collection, $params['filter'] ?? []);

		// Build aggregate columns
		$allowedFunctions = ['count', 'sum', 'avg', 'min', 'max'];
		$selectExpressions = [];

		foreach ($groupBy as $field) {
			if ($collection->fields->has($field)) {
				$col = $collection->fields->get($field)->getColumn();
				$selectExpressions[] = $col;
				$query->groupBy($col);
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
					$selectExpressions[] = 'COUNT(*) AS count_star';
				} elseif ($collection->fields->has($field)) {
					$col = $collection->fields->get($field)->getColumn();
					$alias = $func . '_' . $field;
					$selectExpressions[] = strtoupper($func) . "({$col}) AS {$alias}";
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
		foreach ($filters as $field => $conditions) {
			if ($field === '_or' && is_array($conditions)) {
				$select->where(function (Select\QueryBuilder $qb) use ($collection, $conditions) {
					foreach ($conditions as $i => $group) {
						if (!is_array($group)) {
							continue;
						}
						$method = $i === 0 ? 'where' : 'orWhere';
						$qb->{$method}(function (Select\QueryBuilder $inner) use ($collection, $group) {
							$this->applyFilterGroup($inner, $collection, $group);
						});
					}
				});
				continue;
			}

			if ($field === '_and' && is_array($conditions)) {
				$select->where(function (Select\QueryBuilder $qb) use ($collection, $conditions) {
					foreach ($conditions as $group) {
						if (!is_array($group)) {
							continue;
						}
						$qb->where(function (Select\QueryBuilder $inner) use ($collection, $group) {
							$this->applyFilterGroup($inner, $collection, $group);
						});
					}
				});
				continue;
			}

			if (!$collection->fields->has($field) || !is_array($conditions)) {
				continue;
			}

			$col = $collection->fields->get($field)->getColumn();

			foreach ($conditions as $op => $value) {
				$this->applyFilterCondition($select, $col, $op, $value);
			}
		}
	}

	protected function applyFilterGroup(Select\QueryBuilder $qb, CollectionInterface $collection, array $group): void
	{
		foreach ($group as $field => $conditions) {
			if (!$collection->fields->has($field) || !is_array($conditions)) {
				continue;
			}
			$col = $collection->fields->get($field)->getColumn();
			foreach ($conditions as $op => $value) {
				match ($op) {
					'_eq' => $qb->where($col, $value),
					'_neq' => $qb->where($col, '!=', $value),
					'_lt' => $qb->where($col, '<', $value),
					'_lte' => $qb->where($col, '<=', $value),
					'_gt' => $qb->where($col, '>', $value),
					'_gte' => $qb->where($col, '>=', $value),
					'_contains' => $qb->where($col, 'like', "%{$value}%"),
					'_starts_with' => $qb->where($col, 'like', "{$value}%"),
					'_ends_with' => $qb->where($col, 'like', "%{$value}"),
					'_null' => $qb->where($col, '=', null),
					'_nnull' => $qb->where($col, '!=', null),
					default => null,
				};
			}
		}
	}

	protected function applyFilterCondition(Select $select, string $col, string $op, mixed $value): void
	{
		match ($op) {
			'_eq' => $select->where($col, $value),
			'_neq' => $select->where($col, '!=', $value),
			'_lt' => $select->where($col, '<', $value),
			'_lte' => $select->where($col, '<=', $value),
			'_gt' => $select->where($col, '>', $value),
			'_gte' => $select->where($col, '>=', $value),
			'_in' => $select->where($col, 'in', new Parameter($this->parseArrayValue($value))),
			'_nin' => $select->where($col, 'not in', new Parameter($this->parseArrayValue($value))),
			'_null' => $select->where($col, '=', null),
			'_nnull' => $select->where($col, '!=', null),
			'_contains' => $select->where($col, 'like', "%{$value}%"),
			'_starts_with' => $select->where($col, 'like', "{$value}%"),
			'_ends_with' => $select->where($col, 'like', "%{$value}"),
			'_between' => $this->applyBetween($select, $col, $value),
			default => null,
		};
	}

	protected function applyBetween(Select $select, string $col, mixed $value): void
	{
		$parts = $this->parseArrayValue($value);
		if (count($parts) === 2) {
			$select->where($col, 'between', $parts[0], $parts[1]);
		}
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
		foreach ($filters as $field => $conditions) {
			if (!$collection->fields->has($field) || !is_array($conditions)) {
				continue;
			}
			$col = $collection->fields->get($field)->getColumn();
			if (isset($conditions['_eq'])) {
				$query->where($col, $conditions['_eq']);
			}
		}
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
}
