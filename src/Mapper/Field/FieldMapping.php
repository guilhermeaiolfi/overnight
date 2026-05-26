<?php

declare(strict_types=1);

namespace ON\Mapper\Field;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Representation\RepresentationInterface;

final class FieldMapping
{
	public function __construct(
		private readonly ConversionGateway $gateway,
		private readonly FieldContext $field,
	) {
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function to(string $from, mixed $value, string $to): mixed
	{
		return $this->gateway->to($from, $value, $to, $this->field);
	}
}
