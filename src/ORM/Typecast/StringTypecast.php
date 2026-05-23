<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class StringTypecast implements TypecastInterface
{
	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		if ($storage === null) {
			return null;
		}

		if ($storage === '' && $field->isNullable()) {
			return null;
		}

		return is_scalar($storage) || $storage instanceof \Stringable ? (string) $storage : $storage;
	}

	public function fromPhp(mixed $php, FieldInterface $field): mixed
	{
		if ($php === null) {
			return null;
		}

		if (is_string($php) && trim($php) === '' && $field->isNullable()) {
			return null;
		}

		return is_scalar($php) || $php instanceof \Stringable ? (string) $php : $php;
	}
}
