<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class ListValue implements ValueNode
{
	/**
	 * @param list<ValueNode> $values
	 */
	public function __construct(
		public readonly array $values,
	) {
	}

	public function value(): array
	{
		return array_map(fn(ValueNode $value) => $value->value(), $this->values);
	}
}
