<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class ArrayToObjectMapper implements MapperInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function defaultRepresentations(): array
	{
		return [
			'property' => PhpRepresentation::class,
		];
	}

	public function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		return is_array($from) && is_string($to) && class_exists($to);
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		if ($context->collection) {
			return array_map(
				fn (mixed $item): object => is_array($item)
					? $this->mapObject($item, $to, $context)
					: throw new \InvalidArgumentException('Collection mapping expects a list of arrays.'),
				$from
			);
		}

		return $this->mapObject($from, $to, $context);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param class-string $class
	 */
	private function mapObject(array $data, string $class, MappingContext $context): object
	{
		$reflection = new ReflectionClass($class);
		$instance = $reflection->newInstanceWithoutConstructor();

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$key = $this->resolveSourceKey($property);
			if (! array_key_exists($key, $data)) {
				continue;
			}

			$value = $this->convertInboundValue($data[$key], $property, $context);
			$property->setValue($instance, $value);
		}

		return $instance;
	}

	private function resolveSourceKey(ReflectionProperty $property): string
	{
		$attributes = $property->getAttributes(MapFrom::class);
		if ($attributes !== []) {
			return $attributes[0]->newInstance()->name;
		}

		return $property->getName();
	}

	private function convertInboundValue(mixed $value, ReflectionProperty $property, MappingContext $context): mixed
	{
		if ($value === null) {
			return null;
		}

		$sourceRepresentation = $context->sourceRepresentation;
		$propertyRepresentation = $context->propertyRepresentation
			?? $context->outputRepresentation
			?? $this->defaultRepresentations()['property']
			?? PhpRepresentation::class;

		if ($sourceRepresentation === null) {
			return $value;
		}

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return $value;
		}

		/** @var class-string|non-empty-string $typeName */
		$typeName = $type->getName();

		if (! $type->isBuiltin() && is_array($value)) {
			return $this->gateway->structuralMappers()->map(
				$value,
				$typeName,
				$context->withPropertyRepresentation($propertyRepresentation),
			);
		}

		return $this->gateway->to(
			$sourceRepresentation,
			$value,
			$propertyRepresentation,
			FieldContext::named($property->getName(), $typeName, $type->allowsNull()),
		);
	}
}
