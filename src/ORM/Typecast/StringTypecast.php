<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class StringTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if ($value === '' && $field->isNullable()) {
			return null;
		}

		return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $value;
	}

	public function uncast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '' && $field->isNullable()) {
			return null;
		}

		return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $value;
	}
}
