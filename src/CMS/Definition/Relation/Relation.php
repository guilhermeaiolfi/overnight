<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

use ON\CMS\Definition\CollectionDefinition;

class Relation
{
	// Defines if relation can be nullable (child can have no parent). Defaults to false
	public bool $nullable = false;

	// Automatically save related data with parent entity. Defaults to true
	public bool $cascade = true;

	// lazy || eager
	public string $load = "lazy";

	public string $inner_key;

	public string $outer_key;

	public string $collection;

	public string $name;

	protected ?string $interface = null;

	public function __construct(
		public CollectionDefinition $parent
	) {

	}

	public function interface(string $interface): self
	{
		$this->interface = $interface;

		return $this;
	}

	public function getInterface(): string
	{
		return $this->interface;
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

	public function collection(string $collection): self
	{
		$this->collection = $collection;

		return $this;
	}

	public function getCollection(): string
	{
		return $this->collection;
	}

	public function nullable(bool $nullable): self
	{
		$this->nullable = $nullable;

		return $this;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function cascade(bool $cascade): self
	{
		$this->cascade = $cascade;

		return $this;
	}

	public function isCascade(): bool
	{
		return $this->cascade;
	}

	public function load(string $load): self
	{
		$this->load = $load;

		return $this;
	}

	public function getLoadStrategy(): string
	{
		return $this->load;
	}

	public function innerKey(string $key): self
	{
		$this->inner_key = $key;

		return $this;
	}

	public function getInnerKey(): string
	{
		return $this->inner_key;
	}

	public function outerKey(string $key): self
	{
		$this->outer_key = $key;

		return $this;
	}

	public function getOuterKey(): string
	{
		return $this->outer_key;
	}

	public function end(): CollectionDefinition
	{
		return $this->parent;
	}
}
