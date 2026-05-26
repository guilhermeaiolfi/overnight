<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Attribute\Hidden;
use ON\Mapper\Attribute\MapTo;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class ObjectToArrayMapper implements MapperInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function defaultRepresentations(): array
	{
		return [
			'from' => PhpRepresentation::class,
		];
	}

	public function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		if (! is_object($from) || $to !== 'array') {
			return false;
		}

		if ($from instanceof \DateTimeInterface || $from instanceof \BackedEnum) {
			return false;
		}

		return true;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		return $this->mapObject($from, $context);
	}

	private function mapObject(object $object, MappingContext $context): array
	{
		$reflection = new ReflectionClass($object);
		$result = [];

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->getAttributes(Hidden::class) !== []) {
				continue;
			}

			$key = $this->resolveTargetKey($property);
			$value = $property->getValue($object);
			$result[$key] = $this->convertOutboundValue($value, $property, $context);
		}

		return $result;
	}

	private function resolveTargetKey(ReflectionProperty $property): string
	{
		$attributes = $property->getAttributes(MapTo::class);
		if ($attributes !== []) {
			return $attributes[0]->newInstance()->name;
		}

		return $property->getName();
	}

	private function convertOutboundValue(mixed $value, ReflectionProperty $property, MappingContext $context): mixed
	{
		if ($value === null) {
			return null;
		}

		$readRepresentation = $context->sourceRepresentation
			?? $this->defaultRepresentations()['from']
			?? PhpRepresentation::class;
		$outputRepresentation = $context->outputRepresentation;

		$type = $property->getType();
		if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_object($value)) {
			/** @var class-string $typeName */
			$typeName = $type->getName();

			if (
				is_a($value, $typeName)
				&& ! is_subclass_of($typeName, \DateTimeInterface::class)
				&& ! enum_exists($typeName)
			) {
				return $this->gateway->structuralMappers()->map(
					$value,
					'array',
					$context->withSourceRepresentation($readRepresentation),
				);
			}
		}

		if ($outputRepresentation === null || ! $type instanceof ReflectionNamedType) {
			return $value;
		}

		return $this->gateway->to(
			$readRepresentation,
			$value,
			$outputRepresentation,
			FieldContext::named($property->getName(), $type->getName(), $type->allowsNull()),
		);
	}
}
