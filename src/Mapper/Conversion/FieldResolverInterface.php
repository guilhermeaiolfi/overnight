<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;

interface FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
		mixed $extra = null,
	): ?FieldContext;
}
