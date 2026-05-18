<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Display\DisplayTrait;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Interface\InterfaceTrait;
use ON\ORM\Definition\MetadataTrait;

abstract class AbstractRelation implements RelationInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use MetadataTrait;
	// Defines if relation can be nullable (child can have no parent). Defaults to false
	protected bool $nullable = false;

	// Automatically save related data with parent entity. Defaults to true
	protected bool $cascade = true;

	// lazy || eager
	protected string $load = "lazy";

	protected ?string $inner_key = null;

	protected ?string $outer_key = null;

	protected string $collectionName;

	protected string $name;

	protected array $where = [];

	// format: ['key1' => 'asc', 'key2' => 'asc']
	protected array $orderBy = [];

	protected ?string $loader = null;

	public function __construct(
		public CollectionInterface $parent
	) {

	}

	public function getParent(): CollectionInterface
	{
		return $this->parent;
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

	public function collection(string $collectionName): self
	{
		$this->collectionName = $collectionName;

		return $this;
	}

	public function getCollectionName(): string
	{
		return $this->collectionName;
	}

	public function getCollection(): CollectionInterface
	{
		$collection = $this->parent->getRegistry()->getCollection($this->collectionName);
		if ($collection === null) {
			throw new \LogicException("Target collection {$this->collectionName} is not registered.");
		}

		return $collection;
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

	public function where(array $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function getWhere(): array
	{
		return $this->where;
	}

	public function orderBy(array $orderBy): self
	{
		$this->orderBy = $orderBy;

		return $this;
	}

	public function getOrderBy(): array
	{
		return $this->orderBy;
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

	public function innerKey(string $fieldName): self
	{
		$this->inner_key = $fieldName;

		return $this;
	}

	public function getInnerKey(): string
	{
		if ($this->inner_key === null) {
			throw new \LogicException("Inner key is not defined for relation {$this->name}.");
		}

		return $this->inner_key;
	}

	public function getInnerField(): FieldInterface
	{
		return $this->parent->fields->get($this->getInnerKey());
	}

	public function outerKey(string $fieldName): self
	{
		$this->outer_key = $fieldName;

		return $this;
	}

	public function getOuterKey(): string
	{
		if ($this->outer_key === null) {
			throw new \LogicException("Outer key is not defined for relation {$this->name}.");
		}

		return $this->outer_key;
	}

	public function getOuterField(): FieldInterface
	{
		return $this->getCollection()->fields->get($this->getOuterKey());
	}

	public function loader(string $loader): self
	{
		$this->loader = $loader;

		return $this;
	}

	public function getLoader(): string
	{
		return $this->loader;
	}

	public function getCardinality(): string
	{
		return 'single';
	}

	public function isJunction(): bool
	{
		return false;
	}

	public function end(): CollectionInterface
	{
		return $this->parent;
	}
}
