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
	/** @var array<string, list<int>> */
	protected array $attempts = [];

	/** @var array<string, int> */
	protected array $expirations = [];

	protected int $lastPruneAt = 0;

	public function attempt(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$now = time();
		$this->pruneExpired($now);
		$this->cleanup($key, $windowSeconds);

		$count = count($this->attempts[$key] ?? []);

		if ($count >= $maxAttempts) {
			$oldest = $this->attempts[$key][0] ?? $now;
			$retryAfter = $windowSeconds - ($now - $oldest);

			return new RateLimitResult(false, 0, $maxAttempts, max(0, $retryAfter));
		}

		$this->attempts[$key][] = $now;
		$this->expirations[$key] = max($this->expirations[$key] ?? 0, $now + $windowSeconds);

		return new RateLimitResult(true, $maxAttempts - $count - 1, $maxAttempts);
	}

	public function peek(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$now = time();
		$this->pruneExpired($now);
		$this->cleanup($key, $windowSeconds);

		$count = count($this->attempts[$key] ?? []);
		$remaining = max(0, $maxAttempts - $count);

		if ($count >= $maxAttempts) {
			$oldest = $this->attempts[$key][0] ?? $now;
			$retryAfter = $windowSeconds - ($now - $oldest);

			return new RateLimitResult(false, 0, $maxAttempts, max(0, $retryAfter));
		}

		return new RateLimitResult(true, $remaining, $maxAttempts);
	}

	public function reset(string $key): void
	{
		unset($this->attempts[$key], $this->expirations[$key]);
	}

	private function pruneExpired(int $now): void
	{
		// Scan at most once per second, even when many clients are active.
		if ($now <= $this->lastPruneAt || $this->expirations === []) {
			return;
		}

		foreach ($this->expirations as $key => $expiresAt) {
			if ($expiresAt <= $now) {
				unset($this->attempts[$key], $this->expirations[$key]);
			}
		}

		$this->lastPruneAt = $now;
	}

	protected function cleanup(string $key, int $windowSeconds): void
	{
		if (! isset($this->attempts[$key])) {
			return;
		}

		$cutoff = time() - $windowSeconds;
		$remaining = array_values(
			array_filter($this->attempts[$key], fn (int $time) => $time > $cutoff)
		);

		if ($remaining === []) {
			unset($this->attempts[$key], $this->expirations[$key]);

			return;
		}

		$this->attempts[$key] = $remaining;
	}
}
