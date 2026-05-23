<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class DateTypecast implements TypecastInterface
{
	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		if ($storage === null) {
			return null;
		}

		if ($storage instanceof \DateTimeInterface) {
			return $storage instanceof \DateTimeImmutable
				? $storage->setTime(0, 0)
				: \DateTimeImmutable::createFromInterface($storage)->setTime(0, 0);
		}

		if (! is_string($storage) || trim($storage) === '') {
			return $field->isNullable() ? null : $storage;
		}

		try {
			return (new \DateTimeImmutable($storage))->setTime(0, 0);
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid date value.',
				$field->getName(),
				$e
			);
		}
	}

	public function fromPhp(mixed $php, FieldInterface $field): mixed
	{
		if ($php === null) {
			return null;
		}

		if (is_string($php) && trim($php) === '') {
			return $field->isNullable() ? null : $php;
		}

		try {
			if ($php instanceof \DateTimeInterface) {
				return $php->format('Y-m-d');
			}

			return (new \DateTimeImmutable((string) $php))->format('Y-m-d');
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid date value.',
				$field->getName(),
				$e
			);
		}
	}
}
