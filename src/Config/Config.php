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
}
