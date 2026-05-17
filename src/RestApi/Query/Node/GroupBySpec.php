<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class GroupBySpec
{
	public function __construct(
		public readonly ExpressionNode $expression,
		public readonly ?string $alias = null,
		public readonly ?string $responseName = null,
	) {
	}
}
