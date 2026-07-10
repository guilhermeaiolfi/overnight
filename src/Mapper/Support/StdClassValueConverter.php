<?php

declare(strict_types=1);

namespace ON\Mapper\Support;

use DateTimeInterface;
use ON\Mapper\Blueprint\FieldBlueprintEntry;
use ON\Mapper\Blueprint\MappingBlueprint;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\FieldConversionCoordinator;
use ON\Mapper\Conversion\Resolver\BlueprintFieldResolver;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;
use stdClass;

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
		?FieldConversionCoordinator $conversion = null,
	): stdClass {
		$conversion ??= (new FieldConversionCoordinator($gateway))
			->register(new BlueprintFieldResolver())
			->registerConfiguredResolvers($context);
		$object = new stdClass();

		foreach ($data as $key => $value) {
			if (! is_string($key) || $key === '') {
				continue;
			}

			$path = self::path($pathPrefix, $key);
			$object->{$key} = self::toStdClassValue($value, $path, $gateway, $context, $conversion);
		}

		return $object;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function stdClassToArray(
		stdClass $object,
		ConversionGateway $gateway,
		MappingContext $context,
		string $pathPrefix = '',
		?FieldConversionCoordinator $conversion = null,
	): array {
		$conversion ??= (new FieldConversionCoordinator($gateway))
			->register(new BlueprintFieldResolver())
			->registerConfiguredResolvers($context);
		$result = [];

		foreach (get_object_vars($object) as $key => $value) {
			$path = self::path($pathPrefix, $key);
			$result[$key] = self::toArrayValue($value, $path, $gateway, $context, $conversion);
		}

		return $result;
	}

	private static function toStdClassValue(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
		FieldConversionCoordinator $conversion,
	): mixed {
		if (! is_array($value)) {
			return self::convertInboundScalar($value, $path, $gateway, $context, $conversion);
		}

		if ($value === []) {
			return self::mapEmptyArray($path, $context);
		}

		$blueprint = self::resolveBlueprint($context);
		$entry = $blueprint?->resolve($path);

		if (ArrayHelper::isList($value)) {
			return self::mapListInbound($value, $path, $entry, $gateway, $context, $conversion);
		}

		if ($entry !== null && self::isStructuralClassType($entry->type)) {
			return self::mapStructuralInbound($value, $entry, $gateway, $context, $path);
		}

		return self::arrayToStdClass($value, $gateway, $context, $path, $conversion);
	}

	private static function toArrayValue(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
		FieldConversionCoordinator $conversion,
	): mixed {
		if ($value instanceof stdClass) {
			return self::stdClassToArray($value, $gateway, $context, $path, $conversion);
		}

		if (is_array($value)) {
			$blueprint = self::resolveBlueprint($context);
			$entry = $blueprint?->resolve($path);

			if (ArrayHelper::isList($value)) {
				return self::mapListOutbound($value, $path, $entry, $gateway, $context, $conversion);
			}

			return array_map(
				static fn (mixed $item, int|string $index): mixed => self::toArrayValue(
					$item,
					self::path($path, (string) $index),
					$gateway,
					$context,
					$conversion,
				),
				$value,
				array_keys($value),
			);
		}

		return self::convertOutboundScalar($value, $path, $gateway, $context, $conversion);
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
		FieldConversionCoordinator $conversion,
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
				$conversion,
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
		FieldConversionCoordinator $conversion,
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
				$conversion,
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

		return $gateway->getMappers()->map($value, $entry->type, $nestedContext);
	}

	private static function mapStructuralOutbound(
		object $value,
		FieldBlueprintEntry $entry,
		ConversionGateway $gateway,
		MappingContext $context,
	): array {
		$nestedContext = self::nestedContext($context, $entry);

		/** @var array<string, mixed> */
		return $gateway->getMappers()->map($value, 'array', $nestedContext);
	}

	private static function nestedContext(MappingContext $context, FieldBlueprintEntry $entry): MappingContext
	{
		return $context->withMapperClass($entry->mapperClass);
	}

	private static function mapEmptyArray(string $path, MappingContext $context): mixed
	{
		$blueprint = self::resolveBlueprint($context);
		$entry = $blueprint?->resolve($path);

		if ($entry !== null && self::isStructuralClassType($entry->type)) {
			return new stdClass();
		}

		if ($entry !== null) {
			return [];
		}

		return new stdClass();
	}

	private static function convertInboundScalar(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
		FieldConversionCoordinator $conversion,
	): mixed {
		if ($context->sourceRepresentation === null) {
			return $value;
		}

		$field = self::resolveField($context, $path, $value, ConversionDirection::Inbound, $conversion);
		if ($field === null) {
			return $value;
		}

		return $conversion->convertScalar($value, $field, $context, ConversionDirection::Inbound);
	}

	private static function convertOutboundScalar(
		mixed $value,
		string $path,
		ConversionGateway $gateway,
		MappingContext $context,
		FieldConversionCoordinator $conversion,
	): mixed {
		if ($context->outputRepresentation === null) {
			return $value;
		}

		$field = self::resolveField($context, $path, $value, ConversionDirection::Outbound, $conversion);
		if ($field === null) {
			return $value;
		}

		return $conversion->convertScalar($value, $field, $context, ConversionDirection::Outbound);
	}

	private static function resolveField(
		MappingContext $context,
		string $path,
		mixed $value,
		ConversionDirection $direction,
		FieldConversionCoordinator $conversion,
	): ?FieldContext {
		$fieldName = self::fieldName($path);

		return $conversion->resolveField($context, $path, $fieldName, $value, $direction);
	}

	private static function fieldName(string $path): string
	{
		$parts = explode('.', $path);

		return (string) array_pop($parts);
	}

	private static function resolveBlueprint(MappingContext $context): ?MappingBlueprint
	{
		foreach ($context->args as $arg) {
			if ($arg instanceof MappingBlueprint) {
				return $arg;
			}
		}

		$first = $context->args[0] ?? null;

		return $first instanceof MappingBlueprint ? $first : null;
	}

	private static function path(string $prefix, string|int $key): string
	{
		$segment = (string) $key;

		return $prefix === '' ? $segment : $prefix . '.' . $segment;
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
