<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Node;

class Node
{
	public array $children = [];

	public ?string $modifier = null;

	public function __construct(
		public string $name,
		public ?Node $parent = null
	) {

	}

	public function getChildren(mixed $filterClass_or_callable = null): array
	{
		if (! isset($filterClass_or_callable)) {
			return $this->children;
		}
		$result = [];
		foreach ($this->children as $child) {
			if (is_string($filterClass_or_callable) && is_a($child, $filterClass_or_callable)) {
				$result[] = $child;
			} elseif (is_callable($filterClass_or_callable)) {
				if (call_user_func($filterClass_or_callable, $child)) {
					$result[] = $child;
				}
			}
		}

		return $result;
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

	public function findNode(string $name): ?Node
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

		return ($this->modifier ?? "") . $this->name;
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
