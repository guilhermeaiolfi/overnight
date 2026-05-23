<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Query\Node\BetweenFilter;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\EmptyFilter;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\NullFilter;
use ON\RestApi\Query\Node\RelationExistsFilter;
use ON\RestApi\Query\Node\SetFilter;
use ON\RestApi\Query\Node\ValueNode;

abstract class AbstractMutationCommand implements MutationCommandInterface
{
	public function isReady(): bool
	{
		return true;
	}

	protected function valuesReady(mixed $value): bool
	{
		if ($value instanceof ValueRef) {
			return $value->isReady();
		}

		if (is_array($value)) {
			foreach ($value as $item) {
				if (! $this->valuesReady($item)) {
					return false;
				}
			}
		}

		if ($value instanceof ValueNode) {
			return $this->valuesReady($value->value());
		}

		if ($value instanceof ComparisonFilter) {
			return $this->valuesReady($value->right);
		}

		if ($value instanceof SetFilter) {
			return $this->valuesReady($value->values);
		}

		if ($value instanceof BetweenFilter) {
			return $this->valuesReady($value->from) && $this->valuesReady($value->to);
		}

		if ($value instanceof LogicalFilter) {
			return $this->valuesReady($value->children);
		}

		if ($value instanceof RelationExistsFilter) {
			return $this->valuesReady($value->filter);
		}

		return true;
	}

	protected function resolveValue(mixed $value): mixed
	{
		if ($value instanceof ValueRef) {
			return $value->resolve();
		}

		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->resolveValue($item);
			}
		}

		if ($value instanceof LiteralValue) {
			return new LiteralValue($this->resolveValue($value->value()));
		}

		if ($value instanceof ComparisonFilter) {
			return new ComparisonFilter($value->left, $value->operator, $this->resolveValue($value->right));
		}

		if ($value instanceof SetFilter) {
			return new SetFilter($value->left, $value->operator, $this->resolveValue($value->values));
		}

		if ($value instanceof BetweenFilter) {
			return new BetweenFilter(
				$value->left,
				$this->resolveValue($value->from),
				$this->resolveValue($value->to),
				$value->negated
			);
		}

		if ($value instanceof NullFilter) {
			return new NullFilter($value->left, $value->negated);
		}

		if ($value instanceof EmptyFilter) {
			return new EmptyFilter($value->left, $value->negated);
		}

		if ($value instanceof LogicalFilter) {
			return new LogicalFilter($value->operator, $this->resolveValue($value->children), $value->negated);
		}

		if ($value instanceof RelationExistsFilter) {
			return new RelationExistsFilter(
				$value->responseName,
				$value->relationName,
				$value->targetCollection,
				$this->resolveValue($value->filter)
			);
		}

		return $value;
	}
}
