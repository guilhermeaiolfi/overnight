<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

class DisplayDefinition
{
	protected string $type;

	public function __construct(
		protected mixed $parent
	) {

	}

	public function type(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): string
	{
		return $this->type;
	}

	/** @return RelationDefinition|FieldDefinition */
	public function end(): mixed
	{
		return $this->parent;
	}
}
