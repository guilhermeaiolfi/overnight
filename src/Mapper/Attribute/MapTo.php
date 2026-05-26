<?php

declare(strict_types=1);

namespace ON\Mapper\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class MapTo
{
	public function __construct(
		public readonly string $name,
	) {
	}
}
