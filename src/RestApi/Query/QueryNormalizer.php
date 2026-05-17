<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\RestApi\Query\Node\BetweenFilter;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\DynamicVariableValue;
use ON\RestApi\Query\Node\EmptyFilter;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\ListValue;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\NullFilter;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationAggregateQuerySpec;
use ON\RestApi\Query\Node\RelationAggregateSelection;
use ON\RestApi\Query\Node\RelationExistsFilter;
use ON\RestApi\Query\Node\RelationQuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SelectionNode;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\SetFilter;
use ON\RestApi\Query\Node\ValueNode;

final class QueryNormalizer
{
	public function __construct(
		private readonly array $dynamicVariables = []
	) {
	}

	public function normalize(QuerySpec $query): QuerySpec
	{
		return new QuerySpec(
			$query->collection,
			$this->selection($query->selection),
			$this->filter($query->filter),
			$query->search,
			$query->sort,
			$query->pagination,
			$query->aggregate,
			$query->groupBy,
			$query->meta
		);
	}

	private function selection(SelectionSet $selection): SelectionSet
	{
		return new SelectionSet(
			array_map(fn(SelectionNode $node) => $this->selectionNode($node), $selection->nodes),
			$selection->explicit
		);
	}

	private function selectionNode(SelectionNode $node): SelectionNode
	{
		if ($node instanceof RelationSelection) {
			return new RelationSelection(
				$node->responseName,
				$node->relationName,
				$node->targetCollection,
				new RelationQuerySpec(
					$this->selection($node->query->selection),
					$this->filter($node->query->filter),
					$node->query->search,
					$node->query->sort,
					$node->query->pagination
				)
			);
		}

		if ($node instanceof RelationAggregateSelection) {
			return new RelationAggregateSelection(
				$node->responseName,
				$node->relationName,
				$node->targetCollection,
				new RelationAggregateQuerySpec(
					$this->filter($node->query->filter),
					$node->query->search,
					$node->query->groupBy,
					$node->query->aggregate,
					$node->query->sort,
					$node->query->pagination
				)
			);
		}

		return $node;
	}

	private function filter(?FilterNode $filter): ?FilterNode
	{
		if ($filter instanceof ComparisonFilter) {
			return new ComparisonFilter($filter->left, $filter->operator, $this->value($filter->right));
		}

		if ($filter instanceof SetFilter) {
			return new SetFilter(
				$filter->left,
				$filter->operator,
				array_map(fn(ValueNode $value) => $this->value($value), $filter->values)
			);
		}

		if ($filter instanceof BetweenFilter) {
			return new BetweenFilter(
				$filter->left,
				$this->value($filter->from),
				$this->value($filter->to),
				$filter->negated
			);
		}

		if ($filter instanceof LogicalFilter) {
			return new LogicalFilter(
				$filter->operator,
				array_map(fn(FilterNode $child) => $this->filter($child), $filter->children),
				$filter->negated
			);
		}

		if ($filter instanceof RelationExistsFilter) {
			return new RelationExistsFilter(
				$filter->responseName,
				$filter->relationName,
				$filter->targetCollection,
				$this->filter($filter->filter)
			);
		}

		if ($filter instanceof NullFilter || $filter instanceof EmptyFilter || $filter === null) {
			return $filter;
		}

		return $filter;
	}

	private function value(ValueNode $value): ValueNode
	{
		if ($value instanceof DynamicVariableValue) {
			return new LiteralValue($this->resolveDynamicVariable($value->name));
		}

		if ($value instanceof ListValue) {
			return new ListValue(array_map(fn(ValueNode $item) => $this->value($item), $value->values));
		}

		return $value;
	}

	private function resolveDynamicVariable(string $name): mixed
	{
		$resolver = $this->dynamicVariables[$name] ?? $this->dynamicVariables['$' . $name] ?? null;

		if ($resolver === null) {
			return match ($name) {
				'now' => date('Y-m-d H:i:s'),
				'today' => date('Y-m-d'),
				default => '$' . $name,
			};
		}

		return is_callable($resolver) ? $resolver() : $resolver;
	}
}
