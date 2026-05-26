<?php

declare(strict_types=1);

namespace ON\Mapper\Support;

use ON\Mapper\Blueprint\FieldBlueprintEntry;
use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Structural\MappingContext;

final class StdClassValueConverter
{
	/**
	 * @param array<string, mixed> $data
	 */
	public static function arrayToStdClass(
		array $data,
		ConversionGateway $gateway,
		MappingContext $context,
		string $pathPrefix = '',
	): \stdClass {
		$object = new \stdClass();

		foreach ($data as $key => $value) {
			if (! is_string($key) || $key === '') {
				continue;
			}

			$path = self::path($pathPrefix, $key);
			$object->{$key} = self::toStdClassValue($value, $path, $gateway, $context);
		}

		return $object;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function stdClassToArray(
		\stdClass $object,
		ConversionGateway $gateway,
		MappingContext $context,
		string $pathPrefix = '',
	): array {
		$result = [];

		foreach (get_object_vars($object) as $key => $value) {
			$path = self::path($pathPrefix, $key);
			$result[$key] = self::toArrayValue($value, $path, $gateway, $context);
		}

		return $result;
	}

	private static function toStdClassValue(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
	): mixed {
		if (! is_array($value)) {
			return self::convertInboundScalar($value, $path, $gateway, $context);
		}

		if ($value === []) {
			return self::mapEmptyArray($path, $context);
		}

		$entry = $context->blueprint?->resolve($path);

		if (ArrayHelper::isList($value)) {
			return self::mapListInbound($value, $path, $entry, $gateway, $context);
		}

		if ($entry !== null && self::isStructuralClassType($entry->type)) {
			return self::mapStructuralInbound($value, $entry, $gateway, $context, $path);
		}

		return self::arrayToStdClass($value, $gateway, $context, $path);
	}

	private static function toArrayValue(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
	): mixed {
		if ($value instanceof \stdClass) {
			return self::stdClassToArray($value, $gateway, $context, $path);
		}

		if (is_array($value)) {
			$entry = $context->blueprint?->resolve($path);

			if (ArrayHelper::isList($value)) {
				return self::mapListOutbound($value, $path, $entry, $gateway, $context);
			}

			return array_map(
				static fn (mixed $item, int|string $index): mixed => self::toArrayValue(
					$item,
					self::path($path, (string) $index),
					$gateway,
					$context,
				),
				$value,
				array_keys($value),
			);
		}

		return self::convertOutboundScalar($value, $path, $gateway, $context);
	}

	/**
	 * @param array<int|string, mixed> $value
	 * @return list<mixed>
	 */
	private static function mapListInbound(
		array $value,
		string $path,
		?FieldBlueprintEntry $entry,
		ConversionGateway $gateway,
		MappingContext $context,
	): array {
		if ($entry !== null && self::isStructuralClassType($entry->type)) {
			return array_map(
				static fn (mixed $item): mixed => is_array($item)
					? self::mapStructuralInbound($item, $entry, $gateway, $context, $path)
					: $item,
				$value,
			);
		}

		return array_map(
			static fn (mixed $item, int|string $index): mixed => self::toStdClassValue(
				$item,
				self::path($path, (string) $index),
				$gateway,
				$context,
			),
			$value,
			array_keys($value),
		);
	}

	/**
	 * @param array<int|string, mixed> $value
	 * @return list<mixed>
	 */
	private static function mapListOutbound(
		array $value,
		string $path,
		?FieldBlueprintEntry $entry,
		ConversionGateway $gateway,
		MappingContext $context,
	): array {
		if ($entry !== null && self::isStructuralClassType($entry->type)) {
			return array_map(
				static fn (mixed $item): mixed => is_object($item) && is_a($item, $entry->type)
					? self::mapStructuralOutbound($item, $entry, $gateway, $context)
					: $item,
				$value,
			);
		}

		return array_map(
			static fn (mixed $item, int|string $index): mixed => self::toArrayValue(
				$item,
				self::path($path, (string) $index),
				$gateway,
				$context,
			),
			$value,
			array_keys($value),
		);
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function mapStructuralInbound(
		array $value,
		FieldBlueprintEntry $entry,
		ConversionGateway $gateway,
		MappingContext $context,
		string $path,
	): object {
		$nestedContext = self::nestedContext($context, $entry);

		return $gateway->structuralMappers()->map($value, $entry->type, $nestedContext);
	}

	private static function mapStructuralOutbound(
		object $value,
		FieldBlueprintEntry $entry,
		ConversionGateway $gateway,
		MappingContext $context,
	): array {
		$nestedContext = self::nestedContext($context, $entry);

		/** @var array<string, mixed> */
		return $gateway->structuralMappers()->map($value, 'array', $nestedContext);
	}

	private static function nestedContext(MappingContext $context, FieldBlueprintEntry $entry): MappingContext
	{
		$propertyRepresentation = $context->propertyRepresentation
			?? $context->outputRepresentation
			?? PhpRepresentation::class;

		return $context
			->withPropertyRepresentation($propertyRepresentation)
			->withMapperClass($entry->mapperClass);
	}

	private static function mapEmptyArray(string $path, MappingContext $context): mixed
	{
		$entry = $context->blueprint?->resolve($path);

		if ($entry !== null && self::isStructuralClassType($entry->type)) {
			return new \stdClass();
		}

		if ($entry !== null) {
			return [];
		}

		return new \stdClass();
	}

	private static function convertInboundScalar(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
	): mixed {
		$sourceRepresentation = $context->sourceRepresentation;
		if ($sourceRepresentation === null) {
			return $value;
		}

		$entry = $context->blueprint?->resolve($path);
		if ($entry === null) {
			return $value;
		}

		$propertyRepresentation = $context->propertyRepresentation
			?? $context->outputRepresentation
			?? PhpRepresentation::class;

		return $gateway->to(
			$sourceRepresentation,
			$value,
			$propertyRepresentation,
			FieldContext::named(self::fieldName($path), $entry->type, $value === null),
		);
	}

	private static function convertOutboundScalar(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
	): mixed {
		$outputRepresentation = $context->outputRepresentation;
		if ($outputRepresentation === null) {
			return $value;
		}

		$entry = $context->blueprint?->resolve($path);
		if ($entry === null) {
			return $value;
		}

		$readRepresentation = $context->sourceRepresentation
			?? PhpRepresentation::class;

		return $gateway->to(
			$readRepresentation,
			$value,
			$outputRepresentation,
			FieldContext::named(self::fieldName($path), $entry->type, $value === null),
		);
	}

	private static function path(string $prefix, string|int $key): string
	{
		$segment = (string) $key;

		return $prefix === '' ? $segment : $prefix . '.' . $segment;
	}

	private static function fieldName(string $path): string
	{
		$parts = explode('.', $path);

		return (string) array_pop($parts);
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
