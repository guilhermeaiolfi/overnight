<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion\Resolver;

use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\Field\FieldContext;

/** Resolves FieldContext from a blueprint entry at a dot path (stdClass walks). */
final class BlueprintFieldContextResolver
{
	public function forPath(MappingBlueprint $blueprint, string $path, mixed $value): ?FieldContext
	{
		$entry = $blueprint->resolve($path);
		if ($entry === null) {
			return null;
		}

		if (class_exists($entry->type)
			&& ! enum_exists($entry->type)
			&& ! is_subclass_of($entry->type, \DateTimeInterface::class)
		) {
			return null;
		}

		$parts = explode('.', $path);

		return FieldContext::named(
			(string) array_pop($parts),
			$entry->type,
			$value === null,
		);
	}
}
