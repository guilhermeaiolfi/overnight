<?php

declare(strict_types=1);

namespace ON\Mapper\Representation;

use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeRegistry;

final class WireRepresentation implements RepresentationInterface
{
	public function __construct(
		private readonly FieldTypeRegistry $fieldTypes,
	) {
	}

	public function toPhp(mixed $value, FieldContext $field): mixed
	{
		$handler = $this->fieldTypes->resolve($field);
		if ($handler !== null) {
			return $handler::toPhp(self::class, $value, $field);
		}

		return $value;
	}

	public function fromPhp(mixed $value, FieldContext $field): mixed
	{
		$handler = $this->fieldTypes->resolve($field);
		if ($handler !== null) {
			return $handler::fromPhp(self::class, $value, $field);
		}

		return $value;
	}
}
