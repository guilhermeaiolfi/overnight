<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

interface TypecastInterface
{
	/** Storage raw → API/JSON-safe value. */
	public function cast(mixed $value, FieldInterface $field): mixed;

	/** API/JSON value → storage raw. */
	public function uncast(mixed $value, FieldInterface $field): mixed;
}
