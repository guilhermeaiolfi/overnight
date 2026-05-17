<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class SelectionSet
{
	/**
	 * @param list<SelectionNode> $nodes
	 */
	public function __construct(
		public readonly array $nodes = [],
		public readonly bool $explicit = false,
	) {
	}
}
