<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class ParentRelationBinder
{
	/**
	 * @param class-string $class
	 */
	public static function bind(object $parent, string $class): void
	{
		$parentClass = new ReflectionClass($class);

		foreach ($parentClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if (! $property->isInitialized($parent)) {
				continue;
			}

			$child = $property->getValue($parent);
			if ($child === null) {
				continue;
			}

			$childClass = PropertyTypeResolver::iterableClassType($property)
				?? PropertyTypeResolver::namedType($property);

			if ($childClass === null || ! PropertyTypeResolver::isStructuralClass($childClass)) {
				continue;
			}

			self::setChildParentRelation($parent, $child, $childClass);
		}
	}

	/**
	 * @param class-string $childClass
	 */
	private static function setChildParentRelation(object $parent, mixed $child, string $childClass): void
	{
		$reflection = new ReflectionClass($childClass);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $childProperty) {
			$parentValue = self::resolveParentValue($parent, $childProperty);
			if ($parentValue === null) {
				continue;
			}

			if (is_array($child)) {
				foreach ($child as $childItem) {
					if (is_object($childItem)) {
						$childProperty->setValue($childItem, $parentValue);
					}
				}

				continue;
			}

			if (is_object($child)) {
				$childProperty->setValue($child, $parentValue);
			}
		}
	}

	private static function resolveParentValue(object $parent, ReflectionProperty $childProperty): mixed
	{
		$type = $childProperty->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		$typeName = $type->getName();
		$parentClass = $parent::class;

		if ($typeName === $parentClass) {
			return $parent;
		}

		if ($typeName !== 'array') {
			return null;
		}

		$iterableParent = PropertyTypeResolver::iterableClassType($childProperty);
		if ($iterableParent === $parentClass) {
			return [$parent];
		}

		return null;
	}
}
