<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class ClassFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'string';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		/** @var class-string $class */
		$class = $field->getType();

		if ($value instanceof $class) {
			return $value;
		}

		return match ($from) {
			StorageRepresentation::class => self::fromStorage($class, $value),
			WireRepresentation::class => self::fromWire($class, $value),
			PhpRepresentation::class => $value,
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		/** @var class-string $class */
		$class = $field->getType();

		return match ($to) {
			StorageRepresentation::class => self::toStorage($class, $value),
			WireRepresentation::class => self::toWire($class, $value),
			PhpRepresentation::class => $value,
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}

	/**
	 * @param class-string $class
	 */
	private static function fromStorage(string $class, mixed $value): mixed
	{
		if (method_exists($class, 'fromStorage')) {
			return $class::fromStorage($value);
		}

		if (is_string($value) && method_exists($class, 'fromString')) {
			return $class::fromString($value);
		}

		return $value;
	}

	/**
	 * @param class-string $class
	 */
	private static function fromWire(string $class, mixed $value): mixed
	{
		if (is_string($value) && method_exists($class, 'fromString')) {
			return $class::fromString($value);
		}

		return self::fromStorage($class, $value);
	}

	/**
	 * @param class-string $class
	 */
	private static function toStorage(string $class, mixed $value): mixed
	{
		if ($value instanceof $class && method_exists($value, 'toStorage')) {
			return $value->toStorage();
		}

		if (is_object($value) && method_exists($value, '__toString')) {
			return (string) $value;
		}

		if ($value instanceof \JsonSerializable) {
			return $value->jsonSerialize();
		}

		return $value;
	}

	/**
	 * @param class-string $class
	 */
	private static function toWire(string $class, mixed $value): mixed
	{
		if ($value instanceof \JsonSerializable) {
			return $value->jsonSerialize();
		}

		return self::toStorage($class, $value);
	}
}
