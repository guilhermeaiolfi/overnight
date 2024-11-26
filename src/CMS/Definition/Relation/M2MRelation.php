<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

class M2MRelation extends Relation
{
	public M2MThroughDefinition $through;
	public array $where;
	public array $order_by;
	// Collection type that will contain loaded entities. By defaults uses Cycle\ORM\Collection\ArrayCollectionFactory
	public string $collection_factory;

	public function through(string $collection): M2MThroughDefinition
	{
		$this->through = new M2MThroughDefinition($this);
		$this->through->collection($collection);

		return $this->through;
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
		$this->order_by = $orderBy;

		return $this;
	}

	public function getOrderBy(): array
	{
		return $this->order_by;
	}

	public function collectionFactory(array $factory): self
	{
		$this->collection_factory = $factory;

		return $this;
	}

	public function getCollectionFactory(): string
	{
		return $this->collection_factory;
	}
}
