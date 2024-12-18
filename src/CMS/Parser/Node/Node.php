<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Node;

class Node
{
	public array $children = [];

	public function __construct(
		public string $name,
		public ?Node $parent = null
	) {

	}

	public function addNode(Node $node): self
	{
		$this->children[] = $node;

		return $this;
	}

	public function hasNode(string $name): bool
	{
		foreach ($this->children as $node) {
			if ($node->name == $name) {
				return true;
			}
		}

		return false;
	}

	public function removeNode(Node $node): bool
	{
		foreach ($this->children as $key => $child) {
			if ($child == $node) {
				unset($this->children[$key]);

				return true;
			}
		}

		return false;
	}

	public function findNode(string $name): Node
	{
		foreach ($this->children as $node) {
			if ($node->name == $name) {
				return $node;
			}
		}

		return null;
	}

	public function toArray(): mixed
	{
		if (! empty($this->children)) {
			$children = [];
			foreach ($this->children as $child) {
				if (isset($children[$child->name])) {
					$children[$child->name] = array_merge($children[$child->name], $child->toArray());
				} else {
					$children[$child->name] = $child->toArray();
				}
			}

			return $children;
		}

		return $this->name;
	}

	public function getPath(int $offset = 0, ?int $length = null): array
	{
		$path = [];
		$node = $this;
		while ($node) {
			$path[] = $node;
			$node = $node->parent;
		}

		$path = array_reverse($path);
		$path = array_slice($path, $offset, $length);

		return $path;
	}
}
