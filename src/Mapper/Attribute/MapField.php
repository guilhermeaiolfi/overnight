<?php

declare(strict_types=1);

namespace ON\Mapper\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class MapField
{
	/**
	 * @param class-string|non-empty-string $type ORM/field type key or PHP class for nested mapping
	 * @param class-string|null $mapper Optional structural mapper class for nested values
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $mapper = null,
	) {
	}
}
