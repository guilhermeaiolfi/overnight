<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Attribute\Hidden;
use ON\Mapper\Attribute\MapTo;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldConversionCoordinator;
use ON\Mapper\Conversion\Resolver\ReflectionFieldContextResolver;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class ObjectToArrayMapper implements MapperInterface
{
	private readonly ReflectionFieldContextResolver $fieldResolver;
	private readonly FieldConversionCoordinator $conversion;

	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
		$this->fieldResolver = new ReflectionFieldContextResolver();
		$this->conversion = new FieldConversionCoordinator($gateway);
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

			if (! $property->isInitialized($object)) {
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

		$structural = $this->mapStructuralValue($value, $property, $context, $readRepresentation);
		if ($structural['handled']) {
			return $structural['value'];
		}

		if ($context->outputRepresentation === null) {
			return $value;
		}

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return $value;
		}

		$field = $this->resolveFieldForProperty($context, $property, $value, ConversionDirection::Outbound);
		if ($field === null) {
			return $value;
		}

		return $this->conversion->convertScalar($value, $field, $context, ConversionDirection::Outbound);
	}

	private function resolveFieldForProperty(
		MappingContext $context,
		ReflectionProperty $property,
		mixed $value,
		ConversionDirection $direction,
	): ?FieldContext {
		$name = $property->getName();

		return $this->conversion->resolveOverride($context, $name, $name, $value, $direction)
			?? $this->fieldResolver->forProperty($property);
	}

	/**
	 * @param class-string $readRepresentation
	 * @return array{handled: bool, value: mixed}
	 */
	private function mapStructuralValue(
		mixed $value,
		ReflectionProperty $property,
		MappingContext $context,
		string $readRepresentation,
	): array {
		$nestedContext = $context->withSourceRepresentation($readRepresentation);

		$classType = PropertyTypeResolver::namedType($property);
		if ($classType !== null && PropertyTypeResolver::isStructuralClass($classType) && is_object($value) && is_a($value, $classType)) {
			return [
				'handled' => true,
				'value' => $this->gateway->getMappers()->map($value, 'array', $nestedContext),
			];
		}

		if (! PropertyTypeResolver::isArrayProperty($property) || ! is_array($value)) {
			return ['handled' => false, 'value' => $value];
		}

		$iterableClass = PropertyTypeResolver::iterableClassType($property);
		if ($iterableClass === null) {
			return ['handled' => false, 'value' => $value];
		}

		if (enum_exists($iterableClass)) {
			return [
				'handled' => true,
				'value' => $this->mapEnumListOutbound($value, $iterableClass, $property, $context, $readRepresentation),
			];
		}

		if (! PropertyTypeResolver::isStructuralClass($iterableClass)) {
			return ['handled' => false, 'value' => $value];
		}

		$result = [];
		foreach ($value as $key => $item) {
			if (is_object($item) && is_a($item, $iterableClass)) {
				$result[$key] = $this->gateway->getMappers()->map($item, 'array', $nestedContext);

				continue;
			}

			$result[$key] = $item;
		}

		return ['handled' => true, 'value' => $result];
	}

	/**
	 * @param class-string<\BackedEnum> $enumClass
	 * @param class-string $readRepresentation
	 */
	private function mapEnumListOutbound(
		array $value,
		string $enumClass,
		ReflectionProperty $property,
		MappingContext $context,
		string $readRepresentation,
	): array {
		if ($context->outputRepresentation === null) {
			return $value;
		}

		$mapping = $context->withSourceRepresentation($readRepresentation);
		$result = [];
		foreach ($value as $key => $item) {
			$field = $this->resolveFieldForProperty($mapping, $property, $item, ConversionDirection::Outbound)
				?? FieldContext::named($property->getName(), $enumClass, $property->getType()?->allowsNull() ?? false);
			$result[$key] = $this->conversion->convertScalar(
				$item,
				$field,
				$mapping,
				ConversionDirection::Outbound,
			);
		}

		return $result;
	}
}
