<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

use Closure;

final readonly class RestHook
{
	/**
	 * @param object|Closure(): ?object $payload
	 */
	public function __construct(
		public string $slot,
		public mixed $payload,
		public bool $assertAuthorization = true,
	) {
	}
}
