<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

class InterfaceDefinition
{
	protected string $type;
	protected array $options;

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

	public function options(array $options): self
	{
		$this->options = $options;

		return $this;
	}

	public function getOptions(): array
	{
		return $this->options;
	}

	/** @return RelationDefinition|FieldDefinition */
	public function end(): mixed
	{
		return $this->parent;
	}
}
