<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Directus;

final readonly class ParsedField
{
	public function __construct(
		public string $field,
		public ?string $function = null,
	) {
	}

	public function isFunction(): bool
	{
		return $this->function !== null;
	}
}
