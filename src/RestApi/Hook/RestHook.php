<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

final readonly class RestHook
{
	/**
	 * @param object|\Closure(): ?object $payload
	 */
	public function __construct(
		public mixed $payload,
		public bool $assertAuthorization = true,
	) {}
}
