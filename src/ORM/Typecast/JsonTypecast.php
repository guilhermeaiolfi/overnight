<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class JsonTypecast implements TypecastInterface
{
	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		if ($storage === null) {
			return null;
		}

		if (is_array($storage)) {
			return $storage;
		}

		if (! is_string($storage)) {
			throw new TypecastException('Invalid JSON value.', $field->getName());
		}

		try {
			return json_decode($storage, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new TypecastException('Invalid JSON value.', $field->getName(), $e);
		}
	}

	public function fromPhp(mixed $php, FieldInterface $field): mixed
	{
		if ($php === null) {
			return null;
		}

		if (is_string($php)) {
			return $php;
		}

		if (! is_array($php)) {
			throw new TypecastException('Invalid JSON value.', $field->getName());
		}

		try {
			return json_encode($php, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new TypecastException('Invalid JSON value.', $field->getName(), $e);
		}
	}
}
