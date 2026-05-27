<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

use ON\Mapper\Field\FieldContext;
use ON\Mapper\Structural\MappingContext;

/**
 * Optional map()->resolver() override only. Return null to let the mapper use its own default.
 *
 * Not used by built-in mappers directly — each mapper calls its own resolver with specific arguments.
 */
interface ScalarFieldResolverOverrideInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string $fieldName,
		mixed $value,
		ConversionDirection $direction,
	): ?FieldContext;
}
