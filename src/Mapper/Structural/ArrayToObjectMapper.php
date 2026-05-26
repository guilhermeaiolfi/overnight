<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Support\ArrayHelper;
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
		$data = ArrayHelper::undot($data);
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

		ParentRelationBinder::bind($instance, $class);

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

		$propertyRepresentation = $context->propertyRepresentation
			?? $context->outputRepresentation
			?? $this->defaultRepresentations()['property']
			?? PhpRepresentation::class;

		$structural = $this->mapStructuralValue($value, $property, $context, $propertyRepresentation);
		if ($structural['handled']) {
			return $structural['value'];
		}

		$sourceRepresentation = $context->sourceRepresentation;
		if ($sourceRepresentation === null) {
			return $value;
		}

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return $value;
		}

		return $this->gateway->to(
			$sourceRepresentation,
			$value,
			$propertyRepresentation,
			FieldContext::named($property->getName(), $type->getName(), $type->allowsNull()),
		);
	}

	/**
	 * @param class-string $propertyRepresentation
	 * @return array{handled: bool, value: mixed}
	 */
	private function mapStructuralValue(
		mixed $value,
		ReflectionProperty $property,
		MappingContext $context,
		string $propertyRepresentation,
	): array {
		$nestedContext = $context->withPropertyRepresentation($propertyRepresentation);

		$classType = PropertyTypeResolver::namedType($property);
		if ($classType !== null && PropertyTypeResolver::isStructuralClass($classType)) {
			if (is_array($value)) {
				return [
					'handled' => true,
					'value' => $this->gateway->structuralMappers()->map($value, $classType, $nestedContext),
				];
			}

			if (is_object($value) && is_a($value, $classType)) {
				return ['handled' => true, 'value' => $value];
			}
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
				'value' => $this->mapEnumList($value, $iterableClass, $property, $context, $propertyRepresentation),
			];
		}

		if (! PropertyTypeResolver::isStructuralClass($iterableClass)) {
			return ['handled' => false, 'value' => $value];
		}

		$result = [];
		foreach ($value as $key => $item) {
			if (is_object($item) && is_a($item, $iterableClass)) {
				$result[$key] = $item;

				continue;
			}

			if (! is_array($item)) {
				throw new \InvalidArgumentException(sprintf(
					'Expected array items for property "%s" mapped to %s.',
					$property->getName(),
					$iterableClass,
				));
			}

			$result[$key] = $this->gateway->structuralMappers()->map($item, $iterableClass, $nestedContext);
		}

		return ['handled' => true, 'value' => $result];
	}

	/**
	 * @param class-string<\BackedEnum> $enumClass
	 * @param class-string $propertyRepresentation
	 * @return list<\BackedEnum>
	 */
	private function mapEnumList(
		array $value,
		string $enumClass,
		ReflectionProperty $property,
		MappingContext $context,
		string $propertyRepresentation,
	): array {
		$sourceRepresentation = $context->sourceRepresentation;
		if ($sourceRepresentation === null) {
			return $value;
		}

		$result = [];
		foreach ($value as $key => $item) {
			$result[$key] = $this->gateway->to(
				$sourceRepresentation,
				$item,
				$propertyRepresentation,
				FieldContext::named($property->getName(), $enumClass, $property->getType()?->allowsNull() ?? false),
			);
		}

		return $result;
	}
}
