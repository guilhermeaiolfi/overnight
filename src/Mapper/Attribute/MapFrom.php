<?php

declare(strict_types=1);

namespace ON\Mapper\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class MapFrom
{
	public function __construct(
		public readonly string $name,
	) {
	}
}
