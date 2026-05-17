<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class SortSpec
{
	public function __construct(
		public readonly ExpressionNode $expression,
		public readonly SortDirection $direction = SortDirection::Asc,
	) {
	}
}
