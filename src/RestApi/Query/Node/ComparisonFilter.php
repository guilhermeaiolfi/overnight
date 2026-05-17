<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class ComparisonFilter implements FilterNode
{
	public function __construct(
		public readonly ExpressionNode $left,
		public readonly ComparisonOperator $operator,
		public readonly ValueNode $right,
	) {
	}
}
