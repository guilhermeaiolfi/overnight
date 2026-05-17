<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class LiteralValue implements ValueNode
{
	public function __construct(
		public readonly mixed $value,
	) {
	}

	public function value(): mixed
	{
		return $this->value;
	}
}
