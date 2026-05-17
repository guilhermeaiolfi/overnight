<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class AggregateSpec
{
	public function __construct(
		public readonly AggregateExpression $expression,
		public readonly string $responseFunction,
		public readonly string $responseField,
	) {
	}
}
