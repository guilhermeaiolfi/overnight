<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\DB\DatabaseInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Node\AggregateSpec;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\GroupBySpec;
use ON\RestApi\Query\Node\PaginationSpec;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SearchField;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\SortDirection;
use ON\RestApi\Query\Node\SortSpec;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Resolver\AbstractRestResolver;
use ON\RestApi\Resolver\Sql\Loader\AliasRegistry;
use ON\RestApi\Resolver\Sql\Loader\LoaderFactory;
use ON\RestApi\Resolver\Sql\Loader\QueryContext;
use ON\RestApi\Resolver\Sql\Loader\RootLoader;
use PDO;

class SqlRestResolver extends AbstractRestResolver
{
	protected ?CycleDatabaseInterface $dbal = null;
	protected SqlFilterApplier $filterApplier;
	protected LoaderFactory $loaderFactory;

	public function __construct(
		protected Registry $registry,
		protected DatabaseInterface $database,
		int $defaultLimit = 100,
		int $maxLimit = 1000,
		protected ?CycleDbalFactory $dbalFactory = null,
		protected ?SqlExpressionCompiler $expressions = null
	) {
		parent::__construct($defaultLimit, $maxLimit);
		$this->dbalFactory ??= new CycleDbalFactory();
		$this->expressions ??= new SqlExpressionCompiler($this->getDatabase());
		$this->filterApplier = new SqlFilterApplier($this->getDatabase(), $this->expressions);
		$this->loaderFactory = LoaderFactory::defaults();
	}

	public function transaction(callable $callback): mixed
	{
		return $this->getDatabase()->transaction($callback);
	}

	public function list(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		$fields = $this->fieldTreeFromSelection($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $fields['requestedFields'] ?? $fields['fields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $fields['relations']);
		$fieldsForSelect = $fields;
		$fieldsForSelect['fields'] = array_values(array_unique(array_merge(
			$fieldsForSelect['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->newSelectQuery($collection, $fieldsForSelect['fields']);
		$this->filterApplier->applyNode($query, $collection, $querySpec->filter);
		$this->applySearchField($query, $collection, $querySpec->search);

		$meta = [];
		$requestedMeta = $querySpec->meta;
		if (in_array('total_count', $requestedMeta, true)) {
			$meta['total_count'] = $this->getDatabase()->select()
				->from($collection->getTable())
				->count();
		}

		if (in_array('filter_count', $requestedMeta, true)) {
			$meta['filter_count'] = (clone $query)->count();
		}

		$this->applySortSpecs($query, $collection, $querySpec->sort);
		$this->applyPaginationSpec($query, $querySpec->pagination);

		$items = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);
		$items = $this->loadRootItems(
			$collection,
			$items,
			$requestedColumnNames,
			$internalRelationKeyColumnNames,
			$fields['relations'],
			$this->deepFromSelection($querySpec->selection),
			$querySpec->selection->explicit
		);

		return [
			'items' => $items,
			'meta' => $meta,
		];
	}

	public function get(CollectionInterface $collection, string $id, ?QuerySpec $querySpec = null): ?array
	{
		if ($querySpec === null) {
			return $this->getVisibleById($collection, $id);
		}

		$fields = $this->fieldTreeFromSelection($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $fields['requestedFields'] ?? $fields['fields']);
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

		$items = $this->loadRootItems(
			$collection,
			[$item],
			$requestedColumnNames,
			$internalRelationKeyColumnNames,
			$fields['relations'],
			$this->deepFromSelection($querySpec->selection),
			$querySpec->selection->explicit
		);

		return $items[0] ?? null;
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		try {
			$input = $this->mapInputToColumns($collection, $input);
			$lastId = $this->getDatabase()->insert($collection->getTable())
				->values($input)
				->run();

			return $this->get($collection, (string) $lastId) ?? [];
		} catch (RestApiError $e) {
			throw $e;
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
		} catch (RestApiError $e) {
			throw $e;
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

	public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		if ($querySpec->aggregate === []) {
			return [];
		}

		$query = $this->getDatabase()->select()->from($collection->getTable());
		$this->filterApplier->applyNode($query, $collection, $querySpec->filter);
		$this->applySearchField($query, $collection, $querySpec->search);

		$selectExpressions = [];

		foreach ($querySpec->groupBy as $group) {
			if (!$group instanceof GroupBySpec) {
				continue;
			}
			$expression = $this->expressions->value($collection, $group->expression, $collection->getTable());
			if ($expression === null) {
				continue;
			}

			$query->groupBy($expression);
			$selectExpression = $this->expressions->select(
				$collection,
				$group->expression,
				$collection->getTable(),
				$group->alias ?? $this->expressions->alias($group->expression)
			);
			if ($selectExpression !== null) {
				$selectExpressions[] = $selectExpression;
			}
		}

		foreach ($querySpec->aggregate as $aggregate) {
			if (!$aggregate instanceof AggregateSpec) {
				continue;
			}
			$expression = $this->expressions->aggregate(
				$collection,
				$aggregate->expression,
				$collection->getTable(),
				$this->aggregateAlias($aggregate->responseFunction, $aggregate->responseField)
			);
			if ($expression !== null) {
				$selectExpressions[] = $expression;
			}
		}

		if ($selectExpressions === []) {
			return [];
		}

		$query->columns($selectExpressions);
		$rows = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);

		return $this->formatAggregateRows($rows, $querySpec->aggregate, $querySpec->groupBy);
	}

	public function clearCache(): void
	{
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

	protected function fieldTreeFromSelection(SelectionSet $selection): array
	{
		$fields = [];
		$requestedFields = [];
		$relations = [];

		foreach ($selection->nodes as $node) {
			if ($node instanceof WildcardSelection) {
				return ['fields' => [], 'requestedFields' => [], 'relations' => []];
			}

			if ($node instanceof FieldSelection) {
				$fields[] = $node->field->field;
				if (!$node->internal) {
					$requestedFields[] = $node->field->field;
				}
				continue;
			}

			if ($node instanceof RelationSelection) {
				$relations[$node->responseName] = $this->fieldTreeFromSelection($node->query->selection);
				if ($node->relationName !== $node->responseName) {
					$relations[$node->responseName]['_relation'] = $node->relationName;
				}
			}
		}

		return [
			'fields' => array_values(array_unique($fields)),
			'requestedFields' => array_values(array_unique($requestedFields)),
			'relations' => $relations,
		];
	}

	protected function deepFromSelection(SelectionSet $selection): array
	{
		$deep = [];
		foreach ($selection->nodes as $node) {
			if (!$node instanceof RelationSelection) {
				continue;
			}

			$entry = [];
			if ($node->query->filter !== null) {
				$entry['_filterNode'] = $node->query->filter;
			}
			if ($node->query->search !== null) {
				$entry['_searchNode'] = $node->query->search;
			}
			if ($node->query->sort !== []) {
				$entry['_sortSpecs'] = $node->query->sort;
			}
			if ($node->query->pagination !== null) {
				$entry['_pagination'] = $node->query->pagination;
			}

			foreach ($this->deepFromSelection($node->query->selection) as $nestedName => $nestedDeep) {
				$entry[$nestedName] = $nestedDeep;
			}

			if ($entry !== []) {
				$deep[$node->responseName] = $entry;
			}
		}

		return $deep;
	}

	protected function applySearchField(object $query, CollectionInterface $collection, ?SearchField $search): void
	{
		$this->applySearch($query, $collection, $search?->term);
	}

	/**
	 * @param list<SortSpec> $sort
	 */
	protected function applySortSpecs(object $query, CollectionInterface $collection, array $sort): void
	{
		foreach ($sort as $item) {
			if (!$item instanceof SortSpec) {
				continue;
			}

			$expression = $this->expressions->value($collection, $item->expression, $collection->getTable());
			if ($expression !== null) {
				$query->orderBy($expression, $item->direction === SortDirection::Desc ? 'DESC' : 'ASC');
			}
		}
	}

	protected function applyPaginationSpec(object $query, ?PaginationSpec $pagination): void
	{
		$limit = $pagination?->limit ?? $this->defaultLimit;
		$limit = min($limit, $this->maxLimit);
		if ($limit < 1) {
			$limit = $this->defaultLimit;
		}

		$query->limit($limit);

		$offset = $pagination?->offset ?? 0;
		if ($offset > 0) {
			$query->offset($offset);
		}
	}

	protected function getVisibleById(CollectionInterface $collection, string $id): ?array
	{
		$columns = $this->getVisibleFields($collection);
		$statement = $this->getDatabase()
			->select()
			->from($collection->getTable())
			->columns($columns)
			->where($this->getPrimaryKeyColumn($collection), $id)
			->limit(1)
			->run();

		try {
			$row = $statement->fetch(CycleStatementInterface::FETCH_ASSOC);
		} finally {
			$statement->close();
		}

		if (!is_array($row)) {
			return null;
		}

		$item = [];
		foreach ($collection->fields as $fieldName => $field) {
			if ($field->isHidden()) {
				continue;
			}

			$column = $field->getColumn();
			if (array_key_exists($column, $row)) {
				$item[(string) $fieldName] = $row[$column];
			}
		}

		return $item;
	}

	/**
	 * @param list<AggregateSpec> $aggregates
	 * @param list<GroupBySpec> $groupBy
	 */
	protected function formatAggregateRows(array $rows, array $aggregates, array $groupBy): array
	{
		$result = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ($groupBy as $group) {
				if (!$group instanceof GroupBySpec) {
					continue;
				}

				$responseName = $group->responseName;
				$alias = $group->alias ?? $this->expressions->alias($group->expression);
				if (array_key_exists($alias, $row)) {
					$entry[$responseName] = $row[$alias];
				}
			}

			foreach ($aggregates as $aggregate) {
				if (!$aggregate instanceof AggregateSpec) {
					continue;
				}

				$alias = $this->aggregateAlias($aggregate->responseFunction, $aggregate->responseField);
				if (array_key_exists($alias, $row)) {
					$entry[$aggregate->responseFunction][$aggregate->responseField] = $row[$alias];
				}
			}

			$result[] = $entry;
		}

		return $result;
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

	protected function loadRootItems(
		CollectionInterface $collection,
		array $items,
		array $requestedColumnNames,
		array $internalRelationKeyColumnNames,
		array $relations,
		array $deep,
		bool $selectionExplicit = false
	): array {
		if ($items === []) {
			return [];
		}

		$columns = array_keys($items[0]);
		$loader = new RootLoader(
			$collection,
			$items,
			$columns,
			$requestedColumnNames === [] && !$selectionExplicit ? $this->getVisibleFields($collection) : $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$relations,
			$deep,
			new QueryContext(
				$this->getDatabase(),
				$this->registry,
				$this->filterApplier,
				$this->expressions,
				new AliasRegistry()
			),
			$this->loaderFactory
		);

		return $loader->load();
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
			$fieldName = (string) $fieldName;
			if (!$collection->fields->has($fieldName)) {
				throw RestApiError::invalidField($fieldName);
			}

			$mapped[$collection->fields->get($fieldName)->getColumn()] = $value;
		}

		return $mapped;
	}

	protected function fieldNamesToColumnNames(CollectionInterface $collection, array $fieldNames): array
	{
		$columnNames = [];
		foreach ($fieldNames as $fieldName) {
			$fieldName = (string) $fieldName;
			if (!$collection->fields->has($fieldName)) {
				throw RestApiError::invalidField($fieldName);
			}

			$columnNames[] = $collection->fields->get($fieldName)->getColumn();
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
				$columnNames[] = (string) $collection->relations->get($targetRelationName)->getInnerKey();
			}
		}

		return array_values(array_unique($columnNames));
	}

	protected function convertDatabaseError(\Throwable $e, CollectionInterface $collection): RestApiError
	{
		$message = $e->getMessage();

		if (str_contains($message, 'UNIQUE constraint') || str_contains($message, 'Duplicate entry')) {
			if (preg_match('/column[s]?\s+[`\']?(\w+)/i', $message, $matches)) {
				$field = $this->columnToFieldName($collection, $matches[1]);
				return new RestApiError(
					"A record with this {$field} already exists.",
					'DUPLICATE',
					$field,
					409,
					$e
				);
			}

			return new RestApiError('A record with these values already exists.', 'DUPLICATE', null, 409, $e);
		}

		if (str_contains($message, 'NOT NULL constraint') || str_contains($message, 'cannot be null')) {
			if (preg_match('/[`\']?(\w+)[`\']?\s+(?:cannot be null|NOT NULL)/i', $message, $matches)) {
				$field = $this->columnToFieldName($collection, $matches[1]);
				return new RestApiError(
					"The field {$field} is required.",
					'REQUIRED_FIELD',
					$field,
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

	protected function columnToFieldName(CollectionInterface $collection, string $column): string
	{
		return $collection->fields->hasColumn($column)
			? $collection->fields->getKeyByColumnName($column)
			: $column;
	}
}
