<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class ComputedFieldSelection implements SelectionNode
{
	public function __construct(
		public readonly ExpressionNode $expression,
		public readonly string $responseName,
		public readonly bool $declared = false,
	) {
	}

	public function responseName(): string
	{
		return $this->responseName;
	}
}
