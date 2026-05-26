<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Support\ArrayHelper;
use ON\Mapper\Support\StdClassValueConverter;

final class ArrayToStdClassMapper implements MapperInterface
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

		return is_array($from) && $to === \stdClass::class;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): mixed
	{
		if ($context->collection) {
			return array_map(
				fn (mixed $item): \stdClass => is_array($item)
					? $this->mapObject($item)
					: throw new \InvalidArgumentException('Collection mapping expects a list of arrays.'),
				$from
			);
		}

		return $this->mapObject($from);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function mapObject(array $data): \stdClass
	{
		return StdClassValueConverter::arrayToStdClass(ArrayHelper::undot($data));
	}
}
