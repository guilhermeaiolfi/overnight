<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Display\DisplayTrait;
use ON\ORM\Definition\Interface\InterfaceTrait;

abstract class AbstractRelation implements RelationInterface
{
	use DisplayTrait;
	use InterfaceTrait;
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

	public function __construct(
		public CollectionInterface $parent
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

	public function end(): CollectionInterface
	{
		return $this->parent;
	}
}
