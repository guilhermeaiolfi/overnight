<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class BoolFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'bool';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return match ($from) {
			PhpRepresentation::class => (bool) $value,
			StorageRepresentation::class, WireRepresentation::class => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		$bool = is_bool($value)
			? $value
			: (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value);

		return match ($to) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => $bool,
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}
}
