<?php

declare(strict_types=1);

namespace ON\Mapper\Representation;

use ON\Mapper\Field\FieldContext;

final class PhpRepresentation implements RepresentationInterface
{
	public function toPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}

	public function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}
}
