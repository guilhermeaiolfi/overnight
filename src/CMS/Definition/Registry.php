<?php

declare(strict_types=1);

namespace ON\CMS\Definition;

class Registry
{
	public array $collections = [];

	public static function collection(string $name = null)
	{

		$collection = new CollectionDefinition();
		$collections[] = $collection;

		if (isset($name)) {
			$collection->name($name);
		}

		return  $collection;
	}

	/** @var CollectionDefinition[] */
	public static function getCollections(): array
	{
		return static::$collections;
	}
}
