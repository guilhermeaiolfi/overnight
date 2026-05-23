<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class JsonTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_array($value)) {
			return $value;
		}

		if (! is_string($value)) {
			throw new TypecastException('Invalid JSON value.', $field->getName());
		}

		try {
			return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new TypecastException('Invalid JSON value.', $field->getName(), $e);
		}
	}

	public function uncast(mixed $value, FieldInterface $field): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value)) {
			return $value;
		}

		if (! is_array($value)) {
			throw new TypecastException('Invalid JSON value.', $field->getName());
		}

		try {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new TypecastException('Invalid JSON value.', $field->getName(), $e);
		}
	}
}
