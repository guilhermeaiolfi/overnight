<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\RepresentationInterface;

interface EdgeConverterInterface
{
	/** @return array{0: class-string<RepresentationInterface>, 1: class-string<RepresentationInterface>} */
	public static function edge(): array;

	public static function supports(FieldContext $field): bool;

	public function convert(mixed $value, FieldContext $field): mixed;
}
