<?php

declare(strict_types=1);

namespace ON\Mapper;

use ON\Mapper\Representation\RepresentationResolver;

function map(mixed $source, ?string $from = null, ?ConversionGateway $gateway = null): MapBuilder
{
	return new MapBuilder(
		$source,
		$gateway ?? ConversionGateway::get(),
		RepresentationResolver::resolve($from),
	);
}
