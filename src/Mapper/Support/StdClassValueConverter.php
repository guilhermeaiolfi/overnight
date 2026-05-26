<?php

declare(strict_types=1);

namespace ON\Mapper\Support;

final class StdClassValueConverter
{
	/**
	 * Converts a nested array value for assignment on a stdClass instance.
	 *
	 * List arrays stay arrays; each element is converted recursively.
	 * Associative arrays become stdClass. Empty arrays in lists become empty stdClass.
	 */
	public static function toStdClassValue(mixed $value): mixed
	{
		if (! is_array($value)) {
			return $value;
		}

		if ($value === []) {
			return new \stdClass();
		}

		if (ArrayHelper::isList($value)) {
			return array_map(static fn (mixed $item): mixed => self::toStdClassValue($item), $value);
		}

		return self::arrayToStdClass($value);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function arrayToStdClass(array $data): \stdClass
	{
		$object = new \stdClass();

		foreach ($data as $key => $value) {
			if (! is_string($key) || $key === '') {
				continue;
			}

			$object->{$key} = self::toStdClassValue($value);
		}

		return $object;
	}

	/**
	 * Converts a stdClass graph back to plain arrays.
	 *
	 * List arrays on properties stay arrays; nested stdClass becomes associative arrays.
	 */
	public static function toArrayValue(mixed $value): mixed
	{
		if ($value instanceof \stdClass) {
			return self::stdClassToArray($value);
		}

		if (is_array($value)) {
			return array_map(static fn (mixed $item): mixed => self::toArrayValue($item), $value);
		}

		return $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function stdClassToArray(\stdClass $object): array
	{
		$result = [];

		foreach (get_object_vars($object) as $key => $value) {
			$result[$key] = self::toArrayValue($value);
		}

		return $result;
	}
}
