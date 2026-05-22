<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\QueryParameters;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Query\Node\BetweenFilter;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\EmptyFilter;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\LogicalOperator;
use ON\RestApi\Query\Node\NullFilter;
use ON\RestApi\Query\Node\RelationExistsFilter;
use ON\RestApi\Query\Node\SetFilter;
use ON\RestApi\Query\Node\SetOperator;
use ON\RestApi\Query\Node\ValueNode;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Handler\AliasRegistry;

class SqlFilterApplier
{
	public function __construct(
		protected DatabaseInterface $database,
		protected SqlExpressionCompiler $expressions
	) {
	}

	public function applyNode(
		object $query,
		CollectionInterface $collection,
		?FilterNode $filter,
		?string $tableAlias = null,
		?AliasRegistry $aliases = null
	): void
	{
		if ($filter === null) {
			return;
		}

		$this->applyFilterNode($query, $collection, $filter, $tableAlias ?? $collection->getTable(), $aliases ?? new AliasRegistry());
	}

	protected function applyFilterNode(
		object $query,
		CollectionInterface $collection,
		FilterNode $filter,
		string $tableAlias,
		AliasRegistry $aliases
	): void
	{
		if ($filter instanceof LogicalFilter) {
			$this->applyLogicalFilter($query, $collection, $filter, $tableAlias, $aliases);
			return;
		}

		if ($filter instanceof RelationExistsFilter) {
			$this->applyRelationFilterNode($query, $collection, $filter, $tableAlias, $aliases);
			return;
		}

		if ($filter instanceof ComparisonFilter) {
			$this->applyComparisonFilter($query, $collection, $tableAlias, $filter);
			return;
		}

		if ($filter instanceof SetFilter) {
			$expression = $this->expressions->value($collection, $filter->left, $tableAlias);
			if ($expression !== null) {
				$query->where(
					$expression,
					$filter->operator === SetOperator::In ? 'IN' : 'NOT IN',
					new Parameter(array_map(fn(ValueNode $value) => $value->value(), $filter->values))
				);
			}
			return;
		}

		if ($filter instanceof BetweenFilter) {
			$expression = $this->expressions->value($collection, $filter->left, $tableAlias);
			if ($expression !== null) {
				$query->where($expression, $filter->negated ? 'NOT BETWEEN' : 'BETWEEN', $filter->from->value(), $filter->to->value());
			}
			return;
		}

		if ($filter instanceof NullFilter) {
			$expression = $this->expressions->value($collection, $filter->left, $tableAlias);
			if ($expression !== null) {
				$query->where($expression, $filter->negated ? '!=' : '=', null);
			}
			return;
		}

		if ($filter instanceof EmptyFilter) {
			$expression = $this->expressions->value($collection, $filter->left, $tableAlias);
			if ($expression === null) {
				return;
			}
			$query->where(function ($nested) use ($expression, $filter) {
				if ($filter->negated) {
					$nested->where($expression, '!=', null);
					$nested->where($expression, '!=', '');
					return;
				}

				$nested->where($expression, '=', null);
				$nested->orWhere($expression, '=', '');
			});
		}
	}

	protected function applyLogicalFilter(
		object $query,
		CollectionInterface $collection,
		LogicalFilter $filter,
		string $tableAlias,
		AliasRegistry $aliases
	): void
	{
		$query->where(function ($nested) use ($collection, $filter, $tableAlias, $aliases) {
			foreach ($filter->children as $index => $child) {
				$method = $filter->operator === LogicalOperator::Or && $index > 0 ? 'orWhere' : 'where';
				$nested->{$method}(fn($inner) => $this->applyFilterNode($inner, $collection, $child, $tableAlias, $aliases));
			}
		});
	}

	protected function applyComparisonFilter(object $query, CollectionInterface $collection, string $tableAlias, ComparisonFilter $filter): void
	{
		$expression = $this->expressions->value($collection, $filter->left, $tableAlias);
		if ($expression === null) {
			return;
		}

		$value = $filter->right->value();
		match ($filter->operator) {
			ComparisonOperator::Eq => $query->where($expression, '=', $value),
			ComparisonOperator::Neq => $query->where($expression, '!=', $value),
			ComparisonOperator::Lt => $query->where($expression, '<', $value),
			ComparisonOperator::Lte => $query->where($expression, '<=', $value),
			ComparisonOperator::Gt => $query->where($expression, '>', $value),
			ComparisonOperator::Gte => $query->where($expression, '>=', $value),
			ComparisonOperator::Contains => $query->where($expression, 'LIKE', '%' . $value . '%'),
			ComparisonOperator::NotContains => $query->whereNot($expression, 'LIKE', '%' . $value . '%'),
			ComparisonOperator::StartsWith => $query->where($expression, 'LIKE', $value . '%'),
			ComparisonOperator::EndsWith => $query->where($expression, 'LIKE', '%' . $value),
		};
	}

	protected function applyRelationFilterNode(
		object $query,
		CollectionInterface $collection,
		RelationExistsFilter $filter,
		string $tableAlias,
		AliasRegistry $aliases
	): void
	{
		$relationName = $filter->relationName;
		if (!$collection->relations->has($relationName)) {
			return;
		}

		$relation = $collection->relations->get($relationName);
		$targetCollection = $relation->getCollection();

		$targetAlias = $aliases->alias($tableAlias . '__' . $filter->responseName);
		$subQuery = $this->database->select(new Fragment('1'));

		if ($relation->isJunction() && $relation instanceof M2MRelation) {
			$through = $relation->through;
			$junctionAlias = $aliases->alias($targetAlias . '__junction');
			$subQuery->from($through->getCollection()->getTable() . ' AS ' . $junctionAlias)
				->innerJoin($targetCollection->getTable(), $targetAlias);
			foreach ($through->throughOuterKeys() as $index => $throughOuterKey) {
				$method = $index === 0 ? 'on' : 'andOn';
				$subQuery->{$method}(
					$junctionAlias . '.' . $through->getCollection()->fields->get($throughOuterKey)->getColumn(),
					'=',
					$targetAlias . '.' . $targetCollection->fields->get($relation->outerKeys()[$index])->getColumn()
				);
			}
			foreach ($through->throughInnerKeys() as $index => $throughInnerKey) {
				$subQuery->where(
					new Expression($junctionAlias . '.' . $through->getCollection()->fields->get($throughInnerKey)->getColumn()),
					'=',
					new Expression($tableAlias . '.' . $collection->fields->get($relation->innerKeys()[$index])->getColumn())
				);
			}
		} else {
			$subQuery->from($targetCollection->getTable() . ' AS ' . $targetAlias);
			foreach ($relation->outerKeys() as $index => $outerKey) {
				$subQuery->where(
					new Expression($targetAlias . '.' . $targetCollection->fields->get($outerKey)->getColumn()),
					'=',
					new Expression($tableAlias . '.' . $collection->fields->get($relation->innerKeys()[$index])->getColumn())
				);
			}
		}

		$this->applyNode($subQuery, $targetCollection, $filter->filter, $targetAlias, $aliases);
		$this->applyRelationWhere($subQuery, $targetCollection, $relation->getWhere(), $targetAlias, $aliases);

		$query->where($this->exists($subQuery));
	}

	protected function exists(object $subQuery): Fragment
	{
		$parameters = new QueryParameters();
		$sql = $subQuery->sqlStatement($parameters);

		return new Fragment('EXISTS (' . $sql . ')', ...$parameters->getParameters());
	}

	protected function applyRelationWhere(
		object $query,
		CollectionInterface $collection,
		array $where,
		string $tableAlias,
		AliasRegistry $aliases
	): void
	{
		if ($where === []) {
			return;
		}

		$filter = (new DirectusQueryParser())->parse($collection, ['filter' => $where])->filter;
		$this->applyNode($query, $collection, $filter, $tableAlias, $aliases);
	}

	protected function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$primary = $collection->getPrimaryKeyFields();
		if (is_array($primary)) {
			$primary = reset($primary);
		}

		return $primary->getColumn();
	}

}
