<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Node\AggregateSpec;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\GroupBySpec;
use ON\RestApi\Query\Node\PaginationSpec;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationAggregateSelection;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SearchField;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\SortDirection;
use ON\RestApi\Query\Node\SortSpec;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Resolver\AbstractDataSource;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerInterface;
use ON\RestApi\Handler\QueryContext;
use ON\RestApi\Handler\RootHandler;

class SqlDataSource extends AbstractDataSource
{
	protected SqlFilterApplier $filterApplier;
	protected HandlerFactory $handlerFactory;

	public function __construct(
		protected Registry $registry,
		protected CycleDatabaseInterface $database,
		int $defaultLimit = 100,
		int $maxLimit = 1000,
		protected ?SqlExpressionCompiler $expressions = null
	) {
		parent::__construct($defaultLimit, $maxLimit);
		$this->expressions ??= new SqlExpressionCompiler($this->database);
		$this->filterApplier = new SqlFilterApplier($this->database, $this->expressions);
		$this->handlerFactory = HandlerFactory::defaults();
	}

	public function transaction(callable $callback): mixed
	{
		return $this->getDatabase()->transaction($callback);
	}

	public function list(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		$selection = $this->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->relationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$aliases = new AliasRegistry();
		$query = $this->newSelectQuery($collection, $fieldsForSelect);
		$this->filterApplier->applyNode($query, $collection, $querySpec->filter, null, $aliases);
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
			$selection['relations'],
			$querySpec->selection->explicit,
			$aliases
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

		$selection = $this->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->relationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$aliases = new AliasRegistry();
		$query = $this->newSelectQuery($collection, $fieldsForSelect);
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
			$selection['relations'],
			$querySpec->selection->explicit,
			$aliases
		);

		return $items[0] ?? null;
	}

	public function create(CollectionInterface $collection, array $input): array
	{
		try {
			$primaryKeyValue = $this->inputPrimaryKeyValue($collection, $input);
			$input = $this->mapInputToColumns($collection, $input);
			$lastId = $this->getDatabase()->insert($collection->getTable())
				->values($input)
				->run();

			$id = $lastId ?? $primaryKeyValue;

			return $id === null ? [] : ($this->get($collection, (string) $id) ?? []);
		} catch (RestApiError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array
	{
		try {
			if ($input === []) {
				return $this->firstByCriteria($collection, $criteria);
			}

			$query = $this->getDatabase()->update($collection->getTable())
				->values($this->mapInputToColumns($collection, $input));
			$this->applyCriteriaFilter($query, $collection, $criteria);
			$query->run();

			return $this->firstByCriteria($collection, $criteria);
		} catch (RestApiError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function delete(CollectionInterface $collection, FilterNode $criteria): bool
	{
		try {
			$query = $this->getDatabase()->delete($collection->getTable());
			$this->applyCriteriaFilter($query, $collection, $criteria);

			return $query->run() > 0;
		} catch (\Throwable $e) {
			throw $this->convertDatabaseError($e, $collection);
		}
	}

	public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		if ($querySpec->aggregate === []) {
			return [];
		}

		$query = $this->getDatabase()->select()->from($collection->getTable());
		$this->filterApplier->applyNode($query, $collection, $querySpec->filter, null, new AliasRegistry());
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
	}

	public function newSelectQuery(CollectionInterface $collection, array $fieldNames = []): \Cycle\Database\Query\SelectQuery
	{
		$query = $this->getDatabase()->select()->from($collection->getTable());
		$columns = $this->buildSelectColumnNames($collection, $fieldNames);

		if ($columns !== null) {
			$query->columns($columns);
		}

		return $query;
	}

	public function selectionPlan(SelectionSet $selection): array
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
				$relations[] = $node;
				continue;
			}

			if ($node instanceof RelationAggregateSelection) {
				throw new RestApiError(
					"Relation aggregate '{$node->responseName}' is not supported by the SQL REST resolver yet.",
					'UNSUPPORTED_RELATION_AGGREGATE',
					$node->responseName,
					400
				);
			}
		}

		return [
			'fields' => array_values(array_unique($fields)),
			'requestedFields' => array_values(array_unique($requestedFields)),
			'relations' => $relations,
		];
	}

	public function applySearchField(object $query, CollectionInterface $collection, ?SearchField $search): void
	{
		$this->applySearch($query, $collection, $search?->term);
	}

	/**
	 * @param list<SortSpec> $sort
	 */
	public function applySortSpecs(object $query, CollectionInterface $collection, array $sort): void
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

	public function applyPaginationSpec(object $query, ?PaginationSpec $pagination): void
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

	public function getVisibleById(CollectionInterface $collection, string $id): ?array
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

	protected function loadRootItems(
		CollectionInterface $collection,
		array $items,
		array $requestedColumnNames,
		array $internalRelationKeyColumnNames,
		array $relations,
		bool $selectionExplicit = false,
		?AliasRegistry $aliases = null
	): array {
		if ($items === []) {
			return [];
		}

		$columns = array_keys($items[0]);
		$root = $this->handlerFactory->root(
			$collection,
			$items,
			$columns,
			$requestedColumnNames === [] && !$selectionExplicit ? $this->getVisibleFields($collection) : $requestedColumnNames,
			$internalRelationKeyColumnNames
		);
		$this->configureQueryHandlers(
			$root,
			$collection,
			$relations,
			new QueryContext(
				$this->database,
				$this->registry,
				$this->filterApplier,
				$this->expressions,
				$aliases ?? new AliasRegistry()
			)
		);
		$root->parseRows();
		$this->loadQueryHandlers($root->getChildren());

		return $root->result();
	}

	private function configureQueryHandlers(
		HandlerInterface $parent,
		CollectionInterface $collection,
		array $relations,
		QueryContext $context
	): void {
		$parentNode = $parent instanceof RootHandler ? $parent->rootNode() : $parent->getNode();
		foreach ($relations as $selection) {
			if (!$selection instanceof RelationSelection) {
				continue;
			}

			$handler = $this->handlerFactory->relation($collection, $selection, $context);
			if ($handler === null) {
				continue;
			}

			$parent->addChild($handler);
			$handler->configureParserNode($parentNode);
			$handler->prepare();
			$this->configureQueryHandlers($handler, $handler->getTargetCollection(), $handler->getNestedRelations(), $context);
		}
	}

	private function loadQueryHandlers(array $handlers): void
	{
		foreach ($handlers as $handler) {
			$handler->load();
			$this->loadQueryHandlers($handler->getChildren());
		}
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

	protected function getDatabase(): CycleDatabaseInterface
	{
		return $this->database;
	}

	public function database(): CycleDatabaseInterface
	{
		return $this->database;
	}

	public function filterApplier(): SqlFilterApplier
	{
		return $this->filterApplier;
	}

	public function newQueryContext(?AliasRegistry $aliases = null): QueryContext
	{
		return new QueryContext(
			$this->database,
			$this->registry,
			$this->filterApplier,
			$this->expressions,
			$aliases ?? new AliasRegistry()
		);
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

	protected function applyCriteriaFilter(object $query, CollectionInterface $collection, FilterNode $criteria): void
	{
		$this->filterApplier->applyNode($query, $collection, $criteria);
	}

	protected function firstByCriteria(CollectionInterface $collection, FilterNode $criteria): ?array
	{
		$query = $this->newSelectQuery($collection);
		$this->applyCriteriaFilter($query, $collection, $criteria);
		$query->limit(1);

		$statement = $query->run();
		try {
			$row = $statement->fetch(CycleStatementInterface::FETCH_ASSOC);
		} finally {
			$statement->close();
		}

		return is_array($row) ? $this->mapRowToFields($collection, $row) : null;
	}

	protected function mapRowToFields(CollectionInterface $collection, array $row): array
	{
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

	protected function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKeyName = $this->getPrimaryKeyName($collection);

		if (array_key_exists($primaryKeyName, $input)) {
			return $input[$primaryKeyName];
		}

		$primaryKeyColumn = $this->getPrimaryKeyColumn($collection);

		return $input[$primaryKeyColumn] ?? null;
	}

	protected function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if (is_array($pk)) {
			$pk = reset($pk);
		}

		return $pk instanceof \ON\ORM\Definition\Field\FieldInterface ? $pk->getName() : 'id';
	}

	public function fieldNamesToColumnNames(CollectionInterface $collection, array $fieldNames): array
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

	public function columnNamesToFieldNames(CollectionInterface $collection, array $columnNames): array
	{
		$fieldNames = [];
		foreach ($columnNames as $columnName) {
			$fieldNames[] = $collection->fields->hasColumn($columnName)
				? $collection->fields->getKeyByColumnName($columnName)
				: $columnName;
		}

		return array_values(array_unique($fieldNames));
	}

	public function relationKeyColumnNames(CollectionInterface $collection, array $relations): array
	{
		$columnNames = [];
		foreach ($relations as $relation) {
			if ($relation instanceof RelationSelection && $collection->relations->has($relation->relationName)) {
				$columnNames[] = $collection->relations->get($relation->relationName)->getInnerField()->getColumn();
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
