<?php

declare(strict_types=1);

namespace ON\Discovery;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

class DiscoveryItems implements IteratorAggregate, Countable
{
	public function __construct(
		private array $items = [],
	) {
	}

	public function add(DiscoveryItem $item): self
	{
		$this->items[] = $item;

		return $this;
	}

	public function filterByLocation(DiscoveryLocation $location): array
	{
		return $this->filter(fn ($item) => $item->getLocation() == $location);
	}

	public function filterByTag(string $tag): array
	{
		return $this->filter(function ($item) use ($tag) {
			return in_array($tag, $item->getTags());
		});
	}

	public function filter($func): array
	{
		return array_filter($this->items, $func);
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function removeFromFile(string $file): void
	{
		$this->items = array_values($this->filter(function ($item) use ($file) {
			return $item->getFile() != $file;
		}));
	}
}
