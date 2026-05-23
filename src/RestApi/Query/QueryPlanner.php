<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\RelationAggregateSelection;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;

final class QueryPlanner implements QueryPlannerInterface
{
	public function __construct(
		private ItemRepositoryInterface $items,
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
		$query = $this->items->select($collection, $fieldsForSelect);
		$this->querySpecCompiler->applyFilters($query, $collection, $querySpec->filter, null, $aliases);
		$this->querySpecCompiler->applySearch($query, $collection, $querySpec->search);

		$meta = [];
		if (in_array('total_count', $querySpec->meta, true)) {
			$meta['total_count'] = $this->items->getDatabase()->select()
				->from($collection->getTable())
				->count();
		}

		if (in_array('filter_count', $querySpec->meta, true)) {
			$meta['filter_count'] = $this->items->count($query);
		}

		$this->querySpecCompiler->applyOrderBy($query, $collection, $querySpec->sort);
		$this->querySpecCompiler->applyPagination($query, $querySpec->pagination);

		$rows = $this->items->fetchAll($query);
		$items = $this->fetchData(
			$collection,
			$rows,
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->fieldNamesToColumnNames($collection, $collection->getVisibleFields())
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations'],
			$aliases
		);

		if ($typed) {
			$items = array_map(
				fn (array $row): array => $this->items->hydrateRow($collection, $row),
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
			return $this->items->findByIdentity($collection, $identity, $typed);
		}

		$selection = $this->selectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->items->select($collection, $fieldsForSelect);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->items->fetchOne($query);
		if ($row === null) {
			return null;
		}

		$items = $this->fetchData(
			$collection,
			[$row],
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->fieldNamesToColumnNames($collection, $collection->getVisibleFields())
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations']
		);

		$item = $items[0] ?? null;

		if ($item === null) {
			return null;
		}

		return $typed ? $this->items->hydrateRow($collection, $item) : $item;
	}

	private function fetchData(
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

		$root = $this->handlers->configuredRoot(
			$collection,
			$rows,
			array_keys($rows[0]),
			$requestedColumnNames,
			$internalRelationKeyColumnNames,
			$relations,
			$aliases ?? new AliasRegistry()
		);

		return $root->fetchData();
	}

	public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array
	{
		if ($querySpec->aggregate === []) {
			return [];
		}

		$query = $this->items->getDatabase()->select()->from($collection->getTable());
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
			$this->items->fetchAll($query),
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
			$fieldNames[] = $collection->getFieldNameByColumn($columnName);
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
