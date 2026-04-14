<?php

declare(strict_types=1);

namespace ON\RateLimit;

use function max;

class RateLimitResult
{
	public function __construct(
		protected bool $allowed,
		protected int $remaining,
		protected int $limit,
		protected int $retryAfter = 0
	) {
	}

	public function isAllowed(): bool
	{
		return $this->allowed;
	}

	public function getRemaining(): int
	{
		return $this->remaining;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	/**
	 * Seconds until the window resets. 0 if allowed.
	 */
	public function getRetryAfter(): int
	{
		return $this->retryAfter;
	}

	/**
	 * Get standard rate limit headers.
	 */
	public function getHeaders(): array
	{
		$headers = [
			'X-RateLimit-Limit' => (string) $this->limit,
			'X-RateLimit-Remaining' => (string) max(0, $this->remaining),
		];

		if (! $this->allowed) {
			$headers['Retry-After'] = (string) $this->retryAfter;
		}

		return $headers;
	}
}
