<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class JsonFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'json';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (! is_string($value)) {
			return $value;
		}

		return match ($from) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return match ($to) {
			PhpRepresentation::class => $value,
			StorageRepresentation::class, WireRepresentation::class => is_string($value)
				? $value
				: json_encode($value, JSON_THROW_ON_ERROR),
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}
}
