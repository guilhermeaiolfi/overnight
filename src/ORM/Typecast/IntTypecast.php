<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class IntTypecast implements TypecastInterface
{
	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		if ($storage === null) {
			return null;
		}

		if (is_string($storage) && trim($storage) === '' && $field->isNullable()) {
			return null;
		}

		if (! is_numeric($storage)) {
			throw new TypecastException('Invalid integer value.', $field->getName());
		}

		return (int) $storage;
	}

	public function fromPhp(mixed $php, FieldInterface $field): mixed
	{
		if ($php === null) {
			return null;
		}

		if (is_string($php) && trim($php) === '' && $field->isNullable()) {
			return null;
		}

		if (! is_numeric($php)) {
			throw new TypecastException('Invalid integer value.', $field->getName());
		}

		return (int) $php;
	}
}
