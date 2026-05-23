<?php

declare(strict_types=1);

namespace ON\ORM\Typecast;

use ON\ORM\Definition\Field\FieldInterface;

interface TypecastInterface
{
	/** Storage value → PHP value. */
	public function toPhp(mixed $storage, FieldInterface $field): mixed;

	/** PHP value → storage value. */
	public function fromPhp(mixed $php, FieldInterface $field): mixed;
}
