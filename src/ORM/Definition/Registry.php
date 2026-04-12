<?php

declare(strict_types=1);

namespace ON\ORM\Definition;

use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Collection\CollectionInterface;

class Registry
{
	public array $collections = [];

	protected array $files = [];

	public function register(CollectionInterface $collection): void
	{
		$this->collections[$collection->getName()] = $collection;
	}

	public function getDefinitionFiles(): array
	{
		$files = [];
		foreach ($this->collections as $name => $collection) {
			$file = $collection->getFileDefinitionLocation();
			if (! isset($files[$file]) || ! is_array($files[$file])) {
				$this->files[$file] = [];
			}
			$files[$file][] = $collection->getName();
		}

		return $files;
	}

	public function collection(string $name): CollectionInterface
	{

		$collection = new Collection($this);
		$this->collections[$name] = $collection;

		// keep track of all files containing colletion definitions
		// that info is useful when caching, to see if we are up to date
		$collection->setFileDefinitionLocation(debug_backtrace(0, 1)[0]["file"]);

		$collection->name($name);

		// by default, set the table name as the same as the collection name
		$collection->table($name);

		return $collection;
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
