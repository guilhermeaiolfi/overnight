<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class SetFilter implements FilterNode
{
	/**
	 * @param list<ValueNode> $values
	 */
	public function __construct(
		public readonly ExpressionNode $left,
		public readonly SetOperator $operator,
		public readonly array $values,
	) {
	}
}
