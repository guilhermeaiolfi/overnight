<?php

declare(strict_types=1);

namespace ON\Mapper\Representation;

use ON\Mapper\Field\FieldContext;

interface RepresentationInterface
{
	public function toPhp(mixed $value, FieldContext $field): mixed;

	public function fromPhp(mixed $value, FieldContext $field): mixed;
}
