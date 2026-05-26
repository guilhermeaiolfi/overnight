<?php

declare(strict_types=1);

namespace ON\Mapper\Support;

final class ArrayHelper
{
	/**
	 * Converts dot-notation keys into nested arrays.
	 *
	 * @param iterable<int|string, mixed> $array
	 * @return array<string, mixed>
	 */
	public static function undot(iterable $array): array
	{
		$array = self::toArray($array);
		$unwrapped = [];

		foreach ($array as $key => $value) {
			$unwrapped[] = self::unwrapValue($key, $value);
		}

		if ($unwrapped === []) {
			return [];
		}

		return array_merge_recursive(...$unwrapped);
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private static function toArray(iterable $array): array
	{
		if (is_array($array)) {
			return $array;
		}

		return iterator_to_array($array);
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private static function unwrapValue(string|int $key, mixed $value): array
	{
		if (is_int($key)) {
			return [$key => $value];
		}

		$keys = explode('.', $key);

		for ($i = array_key_last($keys); $i >= 0; $i--) {
			$value = [$keys[$i] => $value];
		}

		return $value;
	}

	/**
	 * Whether the array is a sequential list (not an associative/map shape).
	 *
	 * @param array<int|string, mixed> $array
	 */
	public static function isList(array $array): bool
	{
		return array_is_list($array);
	}
}
