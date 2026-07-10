<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use Stringable;

final class StringFieldType implements FieldTypeInterface
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

		if ($value === '' && $field->isNullable()) {
			return null;
		}

		return match ($from) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => is_scalar($value) || $value instanceof Stringable
				? (string) $value
				: $value,
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '' && $field->isNullable()) {
			return null;
		}

		return match ($to) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => is_scalar($value) || $value instanceof Stringable
				? (string) $value
				: $value,
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}
}
