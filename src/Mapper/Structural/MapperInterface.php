<?php

declare(strict_types=1);

namespace ON\Mapper\Structural;

use ON\Mapper\Representation\RepresentationInterface;

interface MapperInterface
{
	public function canMap(mixed $from, mixed $to, MappingContext $context): bool;

	public function map(mixed $from, mixed $to, MappingContext $context): mixed;

	/**
	 * @return array{from?: class-string<RepresentationInterface>, property?: class-string<RepresentationInterface>, as?: class-string<RepresentationInterface>}
	 */
	public function defaultRepresentations(): array;
}
