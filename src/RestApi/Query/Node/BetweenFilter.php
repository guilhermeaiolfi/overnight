<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class BetweenFilter implements FilterNode
{
	public function __construct(
		public readonly ExpressionNode $left,
		public readonly ValueNode $from,
		public readonly ValueNode $to,
		public readonly bool $negated = false,
	) {
	}
}
