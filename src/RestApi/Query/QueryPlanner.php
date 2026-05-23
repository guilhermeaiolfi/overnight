<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\HandlerInterface;
use ON\RestApi\Handler\RootHandler;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\RelationAggregateSelection;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;

final class QueryPlanner implements QueryPlannerInterface
{
	public function __construct(
		private SqlDataSource $dataSource,
		private HandlerFactory $handlers,
		private SqlQuerySpecCompiler $querySpecCompiler
	) {
	}

	public function handlers(): HandlerFactory
	{
		return $this->handlers;
	}

	public function list(CollectionInterface $collection, QuerySpec $querySpec, bool $typed = true): array
	{
		$selection = $this->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$aliases = new AliasRegistry();
		$query = $this->dataSource->select($collection, $fieldsForSelect);
		$this->querySpecCompiler->applyFilters($query, $collection, $querySpec->filter, null, $aliases);
		$this->querySpecCompiler->applySearch($query, $collection, $querySpec->search);

		$meta = [];
		if (in_array('total_count', $querySpec->meta, true)) {
			$meta['total_count'] = $this->dataSource->getDatabase()->select()
				->from($collection->getTable())
				->count();
		}

		if (in_array('filter_count', $querySpec->meta, true)) {
			$meta['filter_count'] = $this->dataSource->count($query);
		}

		$this->querySpecCompiler->applyOrderBy($query, $collection, $querySpec->sort);
		$this->querySpecCompiler->applyPagination($query, $querySpec->pagination);

		$rows = $this->dataSource->fetchAll($query);
		$items = $this->assembleRootRows(
			$collection,
			$rows,
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->dataSource->getVisibleFields($collection)
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations'],
			$aliases
		);

		if ($typed) {
			$items = array_map(
				fn (array $row): array => $this->dataSource->castRowToPhp($collection, $row),
				$items
			);
		}

		return [
			'items' => $items,
			'meta' => $meta,
		];
	}

	public function get(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		?QuerySpec $querySpec = null,
		bool $typed = true,
	): ?array {
		if ($querySpec === null) {
			return $this->dataSource->getVisibleByIdentity($collection, $identity, $typed);
		}

		$selection = $this->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->dataSource->select($collection, $fieldsForSelect);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->dataSource->fetchOne($query);
		if ($row === null) {
			return null;
		}

		$items = $this->assembleRootRows(
			$collection,
			[$row],
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->dataSource->getVisibleFields($collection)
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations']
		);

		$item = $items[0] ?? null;

		if ($item === null) {
			return null;
		}

		return $typed ? $this->dataSource->castRowToPhp($collection, $item) : $item;
	}

	private function assembleRootRows(
		CollectionInterface $collection,
		array $rows,
		array $requestedColumnNames,
		array $internalRelationKeyColumnNames,
		array $relations,
		?AliasRegistry $aliases = null
	): array {
		if ($rows === []) {
			return [];
		}

		$root = $this->handlers->root(
			$collection,
			$rows,
			array_keys($rows[0]),
			$requestedColumnNames,
			$internalRelationKeyColumnNames
		);
		$this->configureHandlers($root, $collection, $relations, $aliases ?? new AliasRegistry());
		$root->parseRows();
		$this->loadHandlers($root->getChildren());

		return $root->result();
	}

	private function configureHandlers(
		HandlerInterface $parent,
		CollectionInterface $collection,
		array $relations,
		AliasRegistry $aliases
	): void {
		$parentNode = $parent instanceof RootHandler ? $parent->rootNode() : $parent->getNode();
		foreach ($relations as $selection) {
			if (!$selection instanceof RelationSelection) {
				continue;
			}

			$handler = $this->handlers->relation($collection, $selection, $aliases);
			if ($handler === null) {
				continue;
			}

			$parent->addChild($handler);
			$handler->configureParserNode($parentNode);
			$handler->prepare();
			$this->configureHandlers($handler, $handler->getTargetCollection(), $handler->getNestedRelations(), $aliases);
		}
	}

	private function loadHandlers(array $handlers): void
	{
		foreach ($handlers as $handler) {
			$handler->load();
			$this->loadHandlers($handler->getChildren());
		}
	}

	public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		if ($querySpec->aggregate === []) {
			return [];
		}

		$query = $this->dataSource->getDatabase()->select()->from($collection->getTable());
		$this->querySpecCompiler->applyFilters($query, $collection, $querySpec->filter, null, new AliasRegistry());
		$this->querySpecCompiler->applySearch($query, $collection, $querySpec->search);
		$this->querySpecCompiler->applyGroupBy($query, $collection, $querySpec->groupBy);

		$selectExpressions = array_merge(
			$this->querySpecCompiler->compileGroupBySelects($collection, $querySpec->groupBy),
			$this->querySpecCompiler->compileAggregateSelects(
				$collection,
				$querySpec->aggregate,
				null,
				fn(string $function, string $field): string => $this->aggregateAlias($function, $field)
			)
		);

		if ($selectExpressions === []) {
			return [];
		}

		$query->columns($selectExpressions);

		return $this->formatAggregateRows(
			$this->dataSource->fetchAll($query),
			$querySpec->aggregate,
			$querySpec->groupBy
		);
	}

	private function selectionPlan(SelectionSet $selection): array
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

	private function fieldNamesToColumnNames(CollectionInterface $collection, array $fieldNames): array
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

	private function columnNamesToFieldNames(CollectionInterface $collection, array $columnNames): array
	{
		$fieldNames = [];
		foreach ($columnNames as $columnName) {
			$fieldNames[] = $collection->fields->hasColumn($columnName)
				? $collection->fields->getKeyByColumnName($columnName)
				: $columnName;
		}

		return array_values(array_unique($fieldNames));
	}

	private function getRelationKeyColumnNames(CollectionInterface $collection, array $relations): array
	{
		$columnNames = [];
		foreach ($relations as $relation) {
			if ($relation instanceof RelationSelection && $collection->relations->has($relation->relationName)) {
				foreach ($collection->relations->get($relation->relationName)->innerKeys() as $fieldName) {
					$columnNames[] = $collection->fields->get($fieldName)->getColumn();
				}
			}
		}

		return array_values(array_unique($columnNames));
	}

	private function aggregateAlias(string $function, string $field): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $function . '_' . $field);
	}

	private function formatAggregateRows(array $rows, array $aggregates, array $groupBy): array
	{
		$result = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ($groupBy as $group) {
				if (!$group instanceof \ON\RestApi\Query\Node\GroupBySpec) {
					continue;
				}

				$responseName = $group->responseName;
				$alias = $group->alias ?? $this->querySpecCompiler->alias($group->expression);
				if (array_key_exists($alias, $row)) {
					$entry[$responseName] = $row[$alias];
				}
			}

			foreach ($aggregates as $aggregate) {
				if (!$aggregate instanceof \ON\RestApi\Query\Node\AggregateSpec) {
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
}
