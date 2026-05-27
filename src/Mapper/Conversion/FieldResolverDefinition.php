<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

final readonly class FieldResolverDefinition
{
	/**
	 * @param class-string<FieldResolverInterface> $class
	 */
	public function __construct(
		public string $class,
		public array $args = [],
	) {
	}
}
