<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion\Resolver;

use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\PropertyTypeResolver;
use ReflectionNamedType;
use ReflectionProperty;

/** Resolves FieldContext from a DTO property (skips nested objects/lists). */
final class ReflectionFieldContextResolver
{
	public function forProperty(ReflectionProperty $property): ?FieldContext
	{
		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		$classType = PropertyTypeResolver::namedType($property);
		if ($classType !== null && PropertyTypeResolver::isStructuralClass($classType)) {
			return null;
		}

		if (PropertyTypeResolver::isArrayProperty($property)) {
			$iterableClass = PropertyTypeResolver::iterableClassType($property);
			if ($iterableClass !== null && (enum_exists($iterableClass) || PropertyTypeResolver::isStructuralClass($iterableClass))) {
				return null;
			}
		}

		return FieldContext::named(
			$property->getName(),
			$type->getName(),
			$type->allowsNull(),
		);
	}
}
