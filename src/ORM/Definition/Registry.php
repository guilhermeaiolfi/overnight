<?php

declare(strict_types=1);

namespace ON\ORM\Definition;

use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Collection\CollectionInterface;

class Registry
{
	public array $collections = [];

	public function register(CollectionInterface $collection): void
	{
		$this->collections[$collection->getName()] = $collection;
	}

	public function collection(string $name): CollectionInterface
	{
		$collection = new Collection($this);
		$this->collections[$name] = $collection;

		$collection->name($name);

		return  $collection;
	}

	public function getCollection(string $name): CollectionInterface
	{
		return $this->collections[$name];
	}

	/** @var CollectionInterface[] */
	public function getCollections(): array
	{
		return $this->collections;
	}

	public function getInheritedCollections(): array
	{
		// TODO: look in cycle Schema::getInheritedRoles;

		return [];
	}
}
