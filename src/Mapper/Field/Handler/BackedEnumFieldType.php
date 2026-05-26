<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class BackedEnumFieldType implements FieldTypeInterface
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

		/** @var class-string<\BackedEnum> $enum */
		$enum = $field->getType();

		return match ($from) {
			PhpRepresentation::class => $value,
			StorageRepresentation::class, WireRepresentation::class => $enum::from($value),
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($value === null) {
			return null;
		}

		/** @var \BackedEnum $value */
		return match ($to) {
			PhpRepresentation::class => $value,
			StorageRepresentation::class, WireRepresentation::class => $value->value,
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}
}
