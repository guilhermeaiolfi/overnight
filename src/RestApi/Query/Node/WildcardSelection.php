<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class WildcardSelection implements SelectionNode
{
	public function __construct(
		public readonly bool $visibleOnly = true,
	) {
	}

	public function responseName(): string
	{
		return '*';
	}
}
