<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class FunctionExpression implements ExpressionNode
{
	/**
	 * @param list<ExpressionNode|ValueNode> $arguments
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $arguments,
	) {
	}
}
