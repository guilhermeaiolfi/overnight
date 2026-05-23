<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

final class PassthroughTypecast implements TypecastInterface
{
	public function toPhp(mixed $storage, FieldInterface $field): mixed
	{
		return $storage;
	}

	public function fromPhp(mixed $php, FieldInterface $field): mixed
	{
		return $php;
	}
}
