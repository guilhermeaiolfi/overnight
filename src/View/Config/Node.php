<?php

declare(strict_types=1);

namespace ON\View\Config;

use ON\Config\Config;

class Node extends Config
{
	public function __construct(array &$items, protected mixed $parent)
	{
		$this->setReference($items);
	}

	public function end(): mixed
	{
		return $this->parent;
	}
}
