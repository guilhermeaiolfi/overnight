<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class DateTimeTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format(\DateTimeInterface::ATOM);
		}

		if (! is_string($value) || trim($value) === '') {
			return $field->isNullable() ? null : $value;
		}

		try {
			return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid datetime value.',
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

		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		if (is_string($value) && trim($value) === '') {
			return $field->isNullable() ? null : $value;
		}

		try {
			return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid datetime value.',
				$field->getName(),
				$e
			);
		}
	}
}
