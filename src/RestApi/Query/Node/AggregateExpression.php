<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class AggregateExpression implements ExpressionNode
{
	public function __construct(
		public readonly AggregateFunction $function,
		public readonly ExpressionNode $argument,
		public readonly ?string $alias = null,
		public readonly bool $distinct = false,
	) {
	}
}
