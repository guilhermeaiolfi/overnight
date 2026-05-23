<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class DateTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d');
		}

		if (! is_string($value) || trim($value) === '') {
			return $field->isNullable() ? null : $value;
		}

		try {
			return (new \DateTimeImmutable($value))->format('Y-m-d');
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid date value.',
				$field->getName(),
				$e
			);
		}
	}

	public function uncast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '') {
			return $field->isNullable() ? null : $value;
		}

		try {
			if ($value instanceof \DateTimeInterface) {
				$date = $value;
			} else {
				$date = new \DateTimeImmutable((string) $value);
			}

			return $date->format('Y-m-d');
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid date value.',
				$field->getName(),
				$e
			);
		}
	}
}
