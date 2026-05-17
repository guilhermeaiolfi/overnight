<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class FieldSelection implements SelectionNode
{
	public function __construct(
		public readonly FieldExpression $field,
		public readonly string $responseName,
		public readonly ?string $alias = null,
		public readonly bool $internal = false,
	) {
	}

	public function responseName(): string
	{
		return $this->responseName;
	}
}
