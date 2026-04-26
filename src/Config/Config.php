<?php

declare(strict_types=1);

/**
 * Heavily inspired by Dot from Riku Särkinen
 *
 */

namespace ON\Config;

use Laminas\Stdlib\ArrayUtils;

/**
 * Config
 *
 * Class to deal with nested arrays easily
 *
 * @template TKey of array-key
 * @template TValue mixed
 *
 * @implements \ArrayAccess<TKey, TValue>
 * @implements \IteratorAggregate<TKey, TValue>
 */
class Config extends Dot
{
	public function __construct(array|object|null $items = [], bool $parse = false, string $delimiter = ".")
	{
		// 1. Get defaults from public properties of the child class
		$defaults = get_class_vars(static::class);
		
		// 2. Clean up properties that are internal to Dot or Config
		unset($defaults['items'], $defaults['delimiter'], $defaults['instances']);

		// 3. Initialize Dot with these defaults merged with passed items
		parent::__construct(ArrayUtils::merge($defaults, $this->getArrayItems($items)), $parse, $delimiter);

		// 4. Unset the public properties to redirect access to __get/__set
		foreach (array_keys($defaults) as $name) {
			unset($this->{$name});
		}
	}

	/**
	 * Perform a recursive merge of two multidimensional arrays.
	 *
	 * @codingStandardsIgnoreStart
	 * Copied from https://github.com/laminas/laminas-stdlib/blob/980ce463c29c1a66c33e0eb67961bba895d0e19e/src/ArrayUtils.php#L269
	 * @codingStandardsIgnoreEnd
	 *
	 */
	public function mergeConfig($obj): void
	{
		$values = $this->getArrayItems($obj);
		$this->items = ArrayUtils::merge($this->items, $values);
	}

	public function & __get(string $name): mixed
	{
		if (! isset($this->items[$name])) {
			$this->items[$name] = null;
		}

		return $this->items[$name];
	}

	public function __set(string $name, mixed $value): void
	{
		$this->set($name, $value);
	}
}
