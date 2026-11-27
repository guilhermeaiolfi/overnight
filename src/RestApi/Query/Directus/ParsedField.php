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

	public function alias(string $original): string
	{
		return preg_replace(
			'/[^a-zA-Z0-9_]/',
			'_',
			$this->isFunction() ? $this->function . '_' . $this->field : $original,
		);
	}
}
