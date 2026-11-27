<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;

final class RelationAliases
{
	/** Convert alias branches whose names the collection mapper treats as unknown keys. */
	public static function mapNative(array $row, ?RepresentationSchema $schema, string $output): array
	{
		if ($schema === null || $output === PhpRepresentation::class) {
			return $row;
		}

		foreach ($schema->getRelations() as $relation) {
			$name = $relation->getPath();
			if (! is_array($row[$name] ?? null)) {
				continue;
			}

			$row[$name] = $relation->isMany()
				? array_map(static fn (array $child): array => self::mapChild($child, $relation, $output), $row[$name])
				: self::mapChild($row[$name], $relation, $output);
		}

		return $row;
	}

	private static function mapChild(array $row, RepresentationRelationSchema $relation, string $output): array
	{
		$relatedSchema = $relation->getRelatedSchema();
		$row = self::mapNative($row, $relatedSchema, $output);
		if ($relation->getPath() !== $relation->getRelationName()) {
			$row = map($row)->args($relatedSchema->getCollection())->from(PhpRepresentation::class)->as($output)->to([]);
		}

		return $row;
	}

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
