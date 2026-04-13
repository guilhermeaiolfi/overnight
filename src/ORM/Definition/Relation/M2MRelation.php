<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Select\Loader\ManyToManyLoader;

class M2MRelation extends AbstractRelation
{
	public M2MThrough $through;
	// Collection type that will contain loaded entities. By defaults uses Cycle\ORM\Collection\ArrayCollectionFactory
	protected string $collection_factory;

	protected ?string $loader = ManyToManyLoader::class;

	public function through(string $collection): M2MThrough
	{
		$this->through = new M2MThrough($this);
		$this->through->collection($collection);

		return $this->through;
	}

	public function collectionFactory(string $factory): self
	{
		$this->collection_factory = $factory;

		return $this;
	}

	public function getCollectionFactory(): string
	{
		return $this->collection_factory;
	}
}
