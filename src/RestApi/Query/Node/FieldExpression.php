<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class FieldExpression implements ExpressionNode
{
	public function __construct(
		public readonly string $field,
	) {
	}
}
