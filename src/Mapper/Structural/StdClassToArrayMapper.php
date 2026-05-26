<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Support\StdClassValueConverter;

final class StdClassToArrayMapper implements MapperInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function defaultRepresentations(): array
	{
		return [];
	}

	public function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		return $from instanceof \stdClass && $to === 'array';
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		/** @var \stdClass $from */
		return StdClassValueConverter::stdClassToArray($from);
	}
}
