<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;

class M2MThrough
{
	protected string $collectionName;
	protected ?string $inner_key = null;
	protected ?string $outer_key = null;
	protected array $where = [];

	public function __construct(
		protected M2MRelation $m2m
	) {

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
		$collection = $this->m2m->getParent()->getRegistry()->getCollection($this->collectionName);
		if ($collection === null) {
			throw new \LogicException("Target collection {$this->collectionName} is not registered.");
		}

		return $collection;
	}

	public function innerKey(string $fieldName): self
	{
		$this->inner_key = $fieldName;

		return $this;
	}

	public function getInnerKey(): string
	{
		if ($this->inner_key === null) {
			throw new \LogicException('Inner key is not defined for many-to-many through relation.');
		}

		return $this->inner_key;
	}

	public function getInnerField(): FieldInterface
	{
		return $this->getCollection()->fields->get($this->getInnerKey());
	}

	public function outerKey(string $fieldName): self
	{
		$this->outer_key = $fieldName;

		return $this;
	}

	public function getOuterKey(): string
	{
		if ($this->outer_key === null) {
			throw new \LogicException('Outer key is not defined for many-to-many through relation.');
		}

		return $this->outer_key;
	}

	public function getOuterField(): FieldInterface
	{
		return $this->getCollection()->fields->get($this->getOuterKey());
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

	public function end(): M2MRelation
	{
		return $this->m2m;
	}
}
