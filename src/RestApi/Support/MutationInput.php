<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\Data\Definition\Collection\CollectionInterface;

final class MutationInput
{
	/**
	 * @return array{0: array, 1: array}
	 */
	public static function splitNodeInput(CollectionInterface $collection, array $input): array
	{
		$scalar = [];
		$relations = [];

		foreach ($input as $key => $value) {
			if ($collection->relations->has((string) $key)) {
				$relations[(string) $key] = $value;

				continue;
			}

			$scalar[$key] = $value;
		}

		return [$scalar, $relations];
	}

	public static function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	public static function normalizeRelationItems(mixed $value): array
	{
		if (! is_array($value)) {
			return [];
		}

		return self::isAssociativeArray($value) ? [$value] : $value;
	}
}
