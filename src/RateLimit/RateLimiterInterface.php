<?php

declare(strict_types=1);

namespace ON\RateLimit;

interface RateLimiterInterface
{
	/**
	 * Attempt to consume a token for the given key.
	 * Returns a RateLimitResult indicating if the request is allowed.
	 */
	public function attempt(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult;

	/**
	 * Get the current state for a key without consuming a token.
	 */
	public function peek(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult;

	/**
	 * Reset the counter for a key.
	 */
	public function reset(string $key): void;
}
