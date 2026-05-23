<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class DateTimeTypecast implements TypecastInterface
{
	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		if ($storage === null) {
			return null;
		}

		if ($storage instanceof \DateTimeInterface) {
			return $storage instanceof \DateTimeImmutable
				? $storage
				: \DateTimeImmutable::createFromInterface($storage);
		}

		if (! is_string($storage) || trim($storage) === '') {
			return $field->isNullable() ? null : $storage;
		}

		try {
			return new \DateTimeImmutable($storage);
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid datetime value.',
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

		if ($php instanceof \DateTimeInterface) {
			return $php->format('Y-m-d H:i:s');
		}

		if (is_string($php) && trim($php) === '') {
			return $field->isNullable() ? null : $php;
		}

		try {
			return (new \DateTimeImmutable((string) $php))->format('Y-m-d H:i:s');
		} catch (\Throwable $e) {
			throw new TypecastException(
				'Invalid datetime value.',
				$field->getName(),
				$e
			);
		}
	}
}
