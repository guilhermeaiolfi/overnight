<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion\Resolver;

use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldResolverInterface;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;
use ON\Mapper\Structural\PropertyTypeResolver;
use ReflectionNamedType;
use ReflectionProperty;

/** Resolves FieldContext from a DTO property (skips nested objects/lists). */
final class ReflectionPropertyFieldResolver implements FieldResolverInterface
{
	/** @var list<int|string>|null */
	private ?array $propertyPath = null;
	private bool $firstRun = true;
	private bool $capable = true;

	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext {
		$property = $this->resolveProperty($extra);
		if ($property === null) {
			return null;
		}

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

	private function resolveProperty(mixed $extra): ?ReflectionProperty
	{
		if (! $this->capable) {
			return null;
		}

		if ($this->firstRun) {
			$this->firstRun = false;
			$this->propertyPath = $this->findPropertyPath($extra);
			$this->capable = $this->propertyPath !== null;
		}

		if (! $this->capable || $this->propertyPath === null) {
			return null;
		}

		$current = $extra;
		foreach ($this->propertyPath as $segment) {
			if (! is_array($current) || ! array_key_exists($segment, $current)) {
				return null;
			}

			$current = $current[$segment];
		}

		return $current instanceof ReflectionProperty ? $current : null;
	}

	/**
	 * @return list<int|string>|null
	 */
	private function findPropertyPath(mixed $extra): ?array
	{
		if ($extra instanceof ReflectionProperty) {
			return [];
		}

		if (! is_array($extra)) {
			return null;
		}

		foreach ($extra as $key => $value) {
			if ($value instanceof ReflectionProperty) {
				return [$key];
			}
		}

		return null;
	}
}
