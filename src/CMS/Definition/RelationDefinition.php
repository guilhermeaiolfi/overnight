<?php

declare(strict_types=1);

namespace ON\CMS\Definition;

use ON\CMS\Definition\Display\DisplayTrait;
use ON\CMS\Definition\Display\InterfaceTrait;

class RelationDefinition
{
	use DisplayTrait;
	use InterfaceTrait;

	public string $name;

	public function __construct(
		protected CollectionDefinition $collection
	) {

	}

	public function name(string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function end(): CollectionDefinition
	{
		return $this->collection;
	}
}
