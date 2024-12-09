<?php

declare(strict_types=1);

namespace ON\CMS\Definition;

use ON\CMS\Definition\Collection\Collection;
use ON\CMS\Definition\Collection\CollectionInterface;

class Registry
{
	public static array $collections = [];

	public static function collection(?string $name = null)
	{

		$collection = new Collection();
		static::$collections[] = $collection;

		if (isset($name)) {
			$collection->name($name);
		}

		return  $collection;
	}

	/** @var CollectionInterface[] */
	public static function getCollections(): array
	{
		return static::$collections;
	}
}
