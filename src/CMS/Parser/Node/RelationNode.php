<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Node;

class RelationNode extends Node
{
	public int $method = 0;

	public function __construct(
		public string $name,
		public ?Node $parent = null,
		public ?string $collection = null,
		public ?string $modifier = null
	) {

	}
}
