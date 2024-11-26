<?php

declare(strict_types=1);

namespace ON\CMS\Definition;

use ON\CMS\Definition\Display\DisplayTrait;
use ON\CMS\Definition\Display\InterfaceTrait;

class FieldDefinition
{
	use DisplayTrait;
	use InterfaceTrait;

	protected string $name;
	protected ?string $column = null;
	protected string $type = "int";

	protected bool $pk = false;

	/**
	 * @var callable-array|string|null
	 */
	private array|string|null $typecast = null;

	public function __construct(
		protected CollectionDefinition $collection
	) {

	}

	public function name(string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
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

	public function column(string $column): self
	{
		$this->column = $column;

		return $this;
	}

	public function getColumn(): string
	{
		if (! isset($this->columnm)) {
			return $this->name;
		}

		return $this->column;
	}

	public function primaryKey(bool $pk): self
	{
		$this->pk = $pk;

		return $this;
	}

	public function getPrimaryKey(): bool
	{
		return $this->pk;
	}

	public function isPrimaryKey(): bool
	{
		return $this->pk;
	}

	public function end(): CollectionDefinition
	{
		return $this->collection;
	}

	public function hasTypecast(): bool
	{
		return $this->typecast !== null;
	}

	/**
	 * @param callable-array|string|null $typecast
	 */
	public function typecast(array|string|null $typecast): self
	{
		$this->typecast = $typecast;

		return $this;
	}
}
