<?php

declare(strict_types=1);

namespace ON\Mapper\Blueprint;

use DateTimeInterface;
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
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

	public static function fromCollection(CollectionInterface $collection, int $depth = 0): self
	{
		if ($depth < 0) {
			throw new InvalidArgumentException('Collection blueprint depth cannot be negative.');
		}

		$blueprint = new self();
		$blueprint->collectFromCollection($collection, '', $depth, []);

		return $blueprint;
	}

	public function resolve(string $path): ?FieldBlueprintEntry
	{
		return $this->fields[$path]
			?? $this->fields[self::withoutNumericSegments($path)]
			?? null;
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

	/**
	 * @param array<string, true> $seen
	 */
	private function collectFromCollection(CollectionInterface $collection, string $prefix, int $depth, array $seen): void
	{
		$seenKey = $collection->getName() . ':' . $prefix;
		if (isset($seen[$seenKey])) {
			return;
		}
		$seen[$seenKey] = true;

		foreach ($collection->fields as $name => $field) {
			if (! is_string($name) || $name === '') {
				continue;
			}

			$path = $prefix === '' ? $name : $prefix . '.' . $name;
			$this->fields[$path] = new FieldBlueprintEntry($field->getType());
		}

		if ($depth === 0) {
			return;
		}

		foreach ($collection->relations as $name => $relation) {
			if (! is_string($name) || $name === '') {
				continue;
			}

			$path = $prefix === '' ? $name : $prefix . '.' . $name;
			$this->collectFromCollection($relation->getCollection(), $path, $depth - 1, $seen);
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

		if (enum_exists($typeName) || is_subclass_of($typeName, DateTimeInterface::class)) {
			return new FieldBlueprintEntry($typeName);
		}

		return null;
	}

	private static function withoutNumericSegments(string $path): string
	{
		$segments = array_filter(
			explode('.', $path),
			static fn (string $segment): bool => ! ctype_digit($segment),
		);

		return implode('.', $segments);
	}

	/**
	 * @param class-string|non-empty-string $type
	 */
	private static function isStructuralClassType(string $type): bool
	{
		return class_exists($type)
			&& ! enum_exists($type)
			&& ! is_subclass_of($type, DateTimeInterface::class);
	}
}
