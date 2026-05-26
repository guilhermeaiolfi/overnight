<?php

declare(strict_types=1);

namespace ON\Mapper\Blueprint;

use ON\Mapper\Attribute\MapField;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class MappingBlueprint
{
	/** @var array<string, FieldBlueprintEntry> */
	private array $fields = [];

	/**
	 * @param array<string, FieldBlueprintEntry|string|array<string, mixed>> $definition
	 */
	public static function fromArray(array $definition, string $prefix = ''): self
	{
		$blueprint = new self();

		foreach ($definition as $key => $value) {
			if (! is_string($key) || $key === '') {
				continue;
			}

			$path = $prefix === '' ? $key : $prefix . '.' . $key;

			if ($value instanceof FieldBlueprintEntry) {
				$blueprint->fields[$path] = $value;

				continue;
			}

			if (is_string($value)) {
				$blueprint->fields[$path] = new FieldBlueprintEntry($value);

				continue;
			}

			if (is_array($value)) {
				$nested = self::fromArray($value, $path);
				$blueprint->fields = [...$blueprint->fields, ...$nested->fields];
			}
		}

		return $blueprint;
	}

	/**
	 * @param class-string $class
	 */
	public static function fromClass(string $class): self
	{
		$blueprint = new self();
		$blueprint->collectFromClass($class, '');

		return $blueprint;
	}

	public function resolve(string $path): ?FieldBlueprintEntry
	{
		return $this->fields[$path] ?? null;
	}

	/**
	 * @param class-string $class
	 */
	private function collectFromClass(string $class, string $prefix): void
	{
		$reflection = new ReflectionClass($class);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$path = $prefix === '' ? $property->getName() : $prefix . '.' . $property->getName();
			$entry = self::entryFromProperty($property);

			if ($entry !== null) {
				$this->fields[$path] = $entry;

				if (self::isStructuralClassType($entry->type)) {
					$this->collectFromClass($entry->type, $path);
				}

				continue;
			}

			$type = $property->getType();
			if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && class_exists($type->getName())) {
				$this->collectFromClass($type->getName(), $path);
			}
		}
	}

	private static function entryFromProperty(ReflectionProperty $property): ?FieldBlueprintEntry
	{
		$attributes = $property->getAttributes(MapField::class);
		if ($attributes !== []) {
			$mapField = $attributes[0]->newInstance();

			return new FieldBlueprintEntry($mapField->type, $mapField->mapper);
		}

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
			return null;
		}

		$typeName = $type->getName();

		if (enum_exists($typeName) || is_subclass_of($typeName, \DateTimeInterface::class)) {
			return new FieldBlueprintEntry($typeName);
		}

		return null;
	}

	/**
	 * @param class-string|non-empty-string $type
	 */
	private static function isStructuralClassType(string $type): bool
	{
		return class_exists($type)
			&& ! enum_exists($type)
			&& ! is_subclass_of($type, \DateTimeInterface::class);
	}
}
