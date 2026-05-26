<?php

declare(strict_types=1);

namespace ON\Mapper\Blueprint;

final readonly class FieldBlueprintEntry
{
	/**
	 * @param class-string|non-empty-string $type
	 * @param class-string|null $mapperClass
	 */
	public function __construct(
		public string $type,
		public ?string $mapperClass = null,
	) {
	}
}
