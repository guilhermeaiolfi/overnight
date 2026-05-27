<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\ConversionException;
use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class DateTimeFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'datetime';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return match ($from) {
			PhpRepresentation::class => self::ensureDateTimeImmutable($value, $field),
			StorageRepresentation::class => self::parseStorage($value, $field),
			WireRepresentation::class => self::parseWire($value, $field),
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		$dateTime = self::ensureDateTimeImmutable($value, $field);

		return match ($to) {
			PhpRepresentation::class => $dateTime,
			StorageRepresentation::class => $dateTime->format('Y-m-d H:i:s'),
			WireRepresentation::class => $dateTime->format(\DateTimeInterface::ATOM),
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}

	private static function parseStorage(mixed $value, FieldContext $field): \DateTimeImmutable
	{
		if ($value instanceof \DateTimeInterface) {
			return $value instanceof \DateTimeImmutable
				? $value
				: \DateTimeImmutable::createFromInterface($value);
		}

		if (! is_string($value) || self::isEmpty($value)) {
			throw new ConversionException('Invalid datetime value.', $field->getName());
		}

		try {
			return new \DateTimeImmutable((string) $value);
		} catch (\Throwable $e) {
			throw new ConversionException('Invalid datetime value.', $field->getName(), $e);
		}
	}

	private static function parseWire(mixed $value, FieldContext $field): \DateTimeImmutable
	{
		if ($value instanceof \DateTimeInterface) {
			return $value instanceof \DateTimeImmutable
				? $value
				: \DateTimeImmutable::createFromInterface($value);
		}

		if (! is_string($value) || self::isEmpty($value)) {
			throw new ConversionException('Invalid datetime value.', $field->getName());
		}

		try {
			return new \DateTimeImmutable((string) $value);
		} catch (\Throwable $e) {
			throw new ConversionException('Invalid datetime value.', $field->getName(), $e);
		}
	}

	private static function ensureDateTimeImmutable(mixed $value, FieldContext $field): \DateTimeImmutable
	{
		if ($value instanceof \DateTimeImmutable) {
			return $value;
		}

		if ($value instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($value);
		}

		throw new ConversionException('Expected datetime instance.', $field->getName());
	}

	private static function isEmpty(mixed $value): bool
	{
		return is_string($value) && trim($value) === '';
	}
}
