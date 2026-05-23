<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class IntTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '' && $field->isNullable()) {
			return null;
		}

		if (! is_numeric($value)) {
			throw new TypecastException('Invalid integer value.', $field->getName());
		}

		return (int) $value;
	}

	public function uncast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '' && $field->isNullable()) {
			return null;
		}

		if (! is_numeric($value)) {
			throw new TypecastException('Invalid integer value.', $field->getName());
		}

		return (int) $value;
	}
}
