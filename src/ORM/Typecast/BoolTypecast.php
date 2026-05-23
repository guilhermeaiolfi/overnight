<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class BoolTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
	}

	public function uncast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '' && $field->isNullable()) {
			return null;
		}

		return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
	}
}
