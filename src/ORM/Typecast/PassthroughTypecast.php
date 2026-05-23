<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class PassthroughTypecast implements TypecastInterface
{
	public function cast(mixed $value, FieldInterface $field): mixed
	{
		return $value;
	}

	public function uncast(mixed $value, FieldInterface $field): mixed
	{
		return $value;
	}
}
