<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\FragmentInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Query\Node\AggregateSpec;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\GroupBySpec;
use ON\RestApi\Query\Node\PaginationSpec;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationAggregateQuerySpec;
use ON\RestApi\Query\Node\RelationQuerySpec;
use ON\RestApi\Query\Node\SearchField;
use ON\RestApi\Query\Node\SortDirection;
use ON\RestApi\Query\Node\SortSpec;

class SqlQuerySpecCompiler
{
	public function __construct(
		DatabaseInterface $database,
		protected int $defaultLimit = 100,
		protected int $maxLimit = 1000
	) {
		$this->expressions = new SqlExpressionCompiler($database);
		$this->filters = new SqlFilterApplier($database, $this->expressions);
	}

	protected SqlFilterApplier $filters;
	protected SqlExpressionCompiler $expressions;

	public function apply(
		object $query,
		CollectionInterface $collection,
		QuerySpec|RelationQuerySpec|RelationAggregateQuerySpec $spec,
		?string $tableAlias = null,
		?AliasRegistry $aliases = null
	): void {
		$this->applyFilters($query, $collection, $spec->filter, $tableAlias, $aliases);
		$this->applySearch($query, $collection, $spec->search);
		$this->applyOrderBy($query, $collection, $spec->sort, $tableAlias);

		if ($spec instanceof QuerySpec || $spec instanceof RelationAggregateQuerySpec) {
			$this->applyGroupBy($query, $collection, $spec->groupBy, $tableAlias);
		}

		$this->applyPagination($query, $spec->pagination);
	}

	public function applyFilters(
		object $query,
		CollectionInterface $collection,
		?FilterNode $filter,
		?string $tableAlias = null,
		?AliasRegistry $aliases = null
	): void {
		$this->filters->applyNode($query, $collection, $filter, $tableAlias, $aliases);
	}

	public function applySearch(object $query, CollectionInterface $collection, ?SearchField $search): void
	{
		$term = $search?->term;
		if ($term === null || $term === '') {
			return;
		}

		$stringFields = $this->getStringFields($collection);
		if ($stringFields === []) {
			return;
		}

		$query->where(function ($select) use ($collection, $stringFields, $term) {
			foreach ($stringFields as $index => $fieldName) {
				$method = $index === 0 ? 'where' : 'orWhere';
				$select->{$method}($collection->fields->get($fieldName)->getColumn(), 'like', '%' . $term . '%');
			}
		});
	}

	/**
	 * @param list<SortSpec> $sort
	 */
	public function applyOrderBy(
		object $query,
		CollectionInterface $collection,
		array $sort,
		?string $tableAlias = null
	): void {
		foreach ($this->compileOrderBy($collection, $sort, $tableAlias) as $order) {
			$query->orderBy($order['expression'], $order['direction']);
		}
	}

	/**
	 * @param list<GroupBySpec> $groupBy
	 */
	public function applyGroupBy(
		object $query,
		CollectionInterface $collection,
		array $groupBy,
		?string $tableAlias = null
	): void {
		foreach ($groupBy as $group) {
			if (!$group instanceof GroupBySpec) {
				continue;
			}

			$expression = $this->expressions->value($collection, $group->expression, $tableAlias ?? $collection->getTable());
			if ($expression !== null) {
				$query->groupBy($expression);
			}
		}
	}

	public function applyPagination(object $query, ?PaginationSpec $pagination): void
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

	/**
	 * @param list<SortSpec> $sort
	 * @return list<array{expression: FragmentInterface, direction: string}>
	 */
	public function compileOrderBy(
		CollectionInterface $collection,
		array $sort,
		?string $tableAlias = null
	): array {
		$orders = [];
		foreach ($sort as $item) {
			if (!$item instanceof SortSpec) {
				continue;
			}

			$expression = $this->expressions->value($collection, $item->expression, $tableAlias ?? $collection->getTable());
			if ($expression === null) {
				continue;
			}

			$orders[] = [
				'expression' => $expression,
				'direction' => $item->direction === SortDirection::Desc ? 'DESC' : 'ASC',
			];
		}

		return $orders;
	}

	/**
	 * @param list<GroupBySpec> $groupBy
	 * @return list<FragmentInterface>
	 */
	public function compileGroupBySelects(
		CollectionInterface $collection,
		array $groupBy,
		?string $tableAlias = null
	): array {
		$selectExpressions = [];
		foreach ($groupBy as $group) {
			if (!$group instanceof GroupBySpec) {
				continue;
			}

			$selectExpression = $this->expressions->select(
				$collection,
				$group->expression,
				$tableAlias ?? $collection->getTable(),
				$group->alias ?? $this->expressions->alias($group->expression)
			);
			if ($selectExpression !== null) {
				$selectExpressions[] = $selectExpression;
			}
		}

		return $selectExpressions;
	}

	/**
	 * @param list<AggregateSpec> $aggregates
	 * @return list<FragmentInterface>
	 */
	public function compileAggregateSelects(
		CollectionInterface $collection,
		array $aggregates,
		?string $tableAlias = null,
		?callable $aliasResolver = null
	): array {
		$selectExpressions = [];
		foreach ($aggregates as $aggregate) {
			if (!$aggregate instanceof AggregateSpec) {
				continue;
			}

			$expression = $this->expressions->aggregate(
				$collection,
				$aggregate->expression,
				$tableAlias ?? $collection->getTable(),
				($aliasResolver ?? $this->defaultAggregateAliasResolver())(
					$aggregate->responseFunction,
					$aggregate->responseField
				)
			);
			if ($expression !== null) {
				$selectExpressions[] = $expression;
			}
		}

		return $selectExpressions;
	}

	public function alias(\ON\RestApi\Query\Node\ExpressionNode $expression): string
	{
		return $this->expressions->alias($expression);
	}

	protected function getStringFields(CollectionInterface $collection): array
	{
		$stringTypes = ['string', 'text', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext'];
		$fields = [];

		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden() || $field->isPrimaryKey()) {
				continue;
			}

			if (method_exists($field, 'isSearchable') && $field->isSearchable() === false) {
				continue;
			}

			try {
				if (in_array(strtolower($field->getType()), $stringTypes, true)) {
					$fields[] = $name;
				}
			} catch (\Throwable) {
			}
		}

		return $fields;
	}

	protected function defaultAggregateAliasResolver(): callable
	{
		return static fn(string $function, string $field): string => preg_replace(
			'/[^a-zA-Z0-9_]/',
			'_',
			$function . '_' . $field
		);
	}
}
