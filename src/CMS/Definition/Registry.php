<?php

declare(strict_types=1);

namespace ON\CMS\Definition;

use ON\CMS\Definition\Collection\Collection;
use ON\CMS\Definition\Collection\CollectionInterface;

class Registry
{
	public array $collections = [];

	public function register(CollectionInterface $collection): void
	{
		$this->collections[$collection->getName()] = $collection;
	}

	public function collection(string $name): CollectionInterface
	{

		$collection = new Collection();
		$this->collections[$name] = $collection;

		if (isset($name)) {
			$collection->name($name);
		}

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
}
