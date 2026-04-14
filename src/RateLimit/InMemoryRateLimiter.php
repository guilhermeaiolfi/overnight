<?php

declare(strict_types=1);

namespace ON\RateLimit;

use function array_filter;
use function array_values;
use function count;
use function max;
use function time;

class InMemoryRateLimiter implements RateLimiterInterface
{
	protected array $attempts = [];

	public function attempt(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$this->cleanup($key, $windowSeconds);

		$count = count($this->attempts[$key] ?? []);

		if ($count >= $maxAttempts) {
			$oldest = $this->attempts[$key][0] ?? time();
			$retryAfter = $windowSeconds - (time() - $oldest);

			return new RateLimitResult(false, 0, $maxAttempts, max(0, $retryAfter));
		}

		$this->attempts[$key][] = time();

		return new RateLimitResult(true, $maxAttempts - $count - 1, $maxAttempts);
	}

	public function peek(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$this->cleanup($key, $windowSeconds);

		$count = count($this->attempts[$key] ?? []);
		$remaining = max(0, $maxAttempts - $count);

		if ($count >= $maxAttempts) {
			$oldest = $this->attempts[$key][0] ?? time();
			$retryAfter = $windowSeconds - (time() - $oldest);

			return new RateLimitResult(false, 0, $maxAttempts, max(0, $retryAfter));
		}

		return new RateLimitResult(true, $remaining, $maxAttempts);
	}

	public function reset(string $key): void
	{
		unset($this->attempts[$key]);
	}

	protected function cleanup(string $key, int $windowSeconds): void
	{
		if (! isset($this->attempts[$key])) {
			return;
		}

		$cutoff = time() - $windowSeconds;
		$this->attempts[$key] = array_values(
			array_filter($this->attempts[$key], fn(int $time) => $time > $cutoff)
		);
	}
}
