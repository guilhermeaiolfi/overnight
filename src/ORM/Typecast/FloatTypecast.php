<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class FloatTypecast implements TypecastInterface
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
			throw new TypecastException('Invalid float value.', $field->getName());
		}

		return (float) $value;
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
			throw new TypecastException('Invalid float value.', $field->getName());
		}

		return (float) $value;
	}
}
