<?php

declare(strict_types=1);

namespace ON\Mapper\Field\Handler;

use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;

final class PassthroughFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'text';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		return match ($from) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => $value,
			default => throw UnsupportedConversionException::forRepresentation($from),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		return match ($to) {
			PhpRepresentation::class, StorageRepresentation::class, WireRepresentation::class => $value,
			default => throw UnsupportedConversionException::forRepresentation($to),
		};
	}
}
