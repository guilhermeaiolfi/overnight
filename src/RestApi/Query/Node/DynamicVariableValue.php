<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class DynamicVariableValue implements ValueNode
{
	public function __construct(
		public readonly string $name,
	) {
	}

	public function value(): string
	{
		return '$' . $this->name;
	}
}
