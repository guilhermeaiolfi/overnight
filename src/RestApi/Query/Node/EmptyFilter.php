<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class EmptyFilter implements FilterNode
{
	public function __construct(
		public readonly ExpressionNode $left,
		public readonly bool $negated = false,
	) {
	}
}
