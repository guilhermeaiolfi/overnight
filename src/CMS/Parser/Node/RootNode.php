<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Node;

class RootNode extends Node
{
	public function __construct(
		public ?string $collection = null
	) {
		$this->parent = null;
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

			return [ $this->collection => $children ] ;
		}

		return $this->collection;
	}
}
