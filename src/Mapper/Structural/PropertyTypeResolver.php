<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ReflectionNamedType;
use ReflectionProperty;

final class PropertyTypeResolver
{
	/**
	 * @return class-string|null
	 */
	public static function namedType(ReflectionProperty $property): ?string
	{
		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		$name = $type->getName();

		return $type->isBuiltin() ? null : $name;
	}

	public static function isArrayProperty(ReflectionProperty $property): bool
	{
		$type = $property->getType();

		return $type instanceof ReflectionNamedType && $type->getName() === 'array';
	}

	/**
	 * Resolves iterable element type from PHPDoc (@var Child[], list<Child>, array<Child>).
	 *
	 * @return class-string|null
	 */
	public static function iterableClassType(ReflectionProperty $property): ?string
	{
		$doc = $property->getDocComment();
		if ($doc === false || $doc === '') {
			return null;
		}

		if (preg_match('/@var\s+([^\s]+)/', $doc, $match) !== 1) {
			return null;
		}

		$declared = trim($match[1]);

		if (preg_match('/^(?:array<|list<)([^,>]+)>$/', $declared, $generic) === 1) {
			return self::normalizeClassName($generic[1], $property);
		}

		if (preg_match('/^(.+)\[\]$/', $declared, $arraySuffix) === 1) {
			return self::normalizeClassName($arraySuffix[1], $property);
		}

		return null;
	}

	/**
	 * @return class-string|null
	 */
	private static function normalizeClassName(string $typeName, ReflectionProperty $property): ?string
	{
		$typeName = ltrim($typeName, '\\');

		if (class_exists($typeName) || enum_exists($typeName)) {
			return $typeName;
		}

		$declaring = $property->getDeclaringClass()->getNamespaceName();
		if ($declaring === '') {
			return class_exists($typeName) ? $typeName : null;
		}

		$fqcn = $declaring . '\\' . $typeName;

		return class_exists($fqcn) || enum_exists($fqcn) ? $fqcn : null;
	}

	public static function isStructuralClass(string $class): bool
	{
		return ! is_subclass_of($class, \DateTimeInterface::class) && ! enum_exists($class);
	}
}
