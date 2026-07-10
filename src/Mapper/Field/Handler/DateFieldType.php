<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use DateTimeImmutable;
use DateTimeInterface;
use ON\Mapper\Exception\ConversionException;
use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use Throwable;

final class DateFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'date';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return match ($from) {
			PhpRepresentation::class => self::ensureDate($value, $field),
			StorageRepresentation::class, WireRepresentation::class => self::parseDate($value, $field),
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		$dateTime = $value instanceof DateTimeInterface
			? ($value instanceof DateTimeImmutable
				? $value
				: DateTimeImmutable::createFromInterface($value))->setTime(0, 0)
			: self::parseDate($value, $field);

		return match ($to) {
			PhpRepresentation::class => $dateTime,
			StorageRepresentation::class, WireRepresentation::class => $dateTime->format('Y-m-d'),
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}

	private static function parseDate(mixed $value, FieldContext $field): DateTimeImmutable
	{
		try {
			if ($value instanceof DateTimeInterface) {
				return ($value instanceof DateTimeImmutable
					? $value
					: DateTimeImmutable::createFromInterface($value))->setTime(0, 0);
			}

			return (new DateTimeImmutable((string) $value))->setTime(0, 0);
		} catch (Throwable $e) {
			throw new ConversionException('Invalid date value.', $field->getName(), $e);
		}
	}

	private static function ensureDate(mixed $value, FieldContext $field): DateTimeImmutable
	{
		if ($value instanceof DateTimeImmutable) {
			return $value->setTime(0, 0);
		}

		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
		}

		throw new ConversionException('Expected date instance.', $field->getName());
	}
}
