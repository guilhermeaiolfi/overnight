<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class LogicalFilter implements FilterNode
{
	/**
	 * @param list<FilterNode> $children
	 */
	public function __construct(
		public readonly LogicalOperator $operator,
		public readonly array $children,
		public readonly bool $negated = false,
	) {
	}
}
