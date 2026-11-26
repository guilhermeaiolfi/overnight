<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

final class RelationAliases
{
	/**
	 * @param array<string, string> $aliases
	 */
	public static function apply(array $row, array $aliases): array
	{
		// Alias paths use the response name for ancestor segments.
		uksort($aliases, static fn (string $left, string $right): int => substr_count($left, '.') <=> substr_count($right, '.'));
		foreach ($aliases as $path => $name) {
			self::rename($row, explode('.', $path), $name);
		}

		return $row;
	}

	private static function rename(array &$row, array $path, string $name): void
	{
		$field = array_shift($path);
		if ($path === []) {
			if ($field !== $name && array_key_exists($field, $row)) {
				$row[$name] = $row[$field];
				unset($row[$field]);
			}

			return;
		}

		if (! is_array($row[$field] ?? null)) {
			return;
		}

		$related = &$row[$field];
		if (array_is_list($related)) {
			foreach ($related as &$item) {
				if (is_array($item)) {
					self::rename($item, $path, $name);
				}
			}

			return;
		}

		self::rename($related, $path, $name);
	}
}
