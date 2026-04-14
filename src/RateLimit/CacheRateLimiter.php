<?php

declare(strict_types=1);

namespace ON\RateLimit;

use DateTimeImmutable;
use ON\Cache\CacheInterface;

use function max;
use function time;

class CacheRateLimiter implements RateLimiterInterface
{
	public function __construct(
		protected CacheInterface $cache,
		protected string $prefix = 'ratelimit:'
	) {
	}

	public function attempt(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$cacheKey = $this->prefix . $key;
		$data = $this->cache->get($cacheKey);

		if ($data === null) {
			$data = ['count' => 0, 'start' => time()];
		}

		// Check if window has expired
		if (time() - $data['start'] >= $windowSeconds) {
			$data = ['count' => 0, 'start' => time()];
		}

		if ($data['count'] >= $maxAttempts) {
			$retryAfter = $windowSeconds - (time() - $data['start']);

			return new RateLimitResult(false, 0, $maxAttempts, max(0, $retryAfter));
		}

		$data['count']++;
		$this->cache->put($cacheKey, $data, (new DateTimeImmutable())->modify("+{$windowSeconds} seconds"));

		return new RateLimitResult(true, $maxAttempts - $data['count'], $maxAttempts);
	}

	public function peek(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$cacheKey = $this->prefix . $key;
		$data = $this->cache->get($cacheKey);

		if ($data === null || time() - $data['start'] >= $windowSeconds) {
			return new RateLimitResult(true, $maxAttempts, $maxAttempts);
		}

		$remaining = max(0, $maxAttempts - $data['count']);

		if ($data['count'] >= $maxAttempts) {
			$retryAfter = $windowSeconds - (time() - $data['start']);

			return new RateLimitResult(false, 0, $maxAttempts, max(0, $retryAfter));
		}

		return new RateLimitResult(true, $remaining, $maxAttempts);
	}

	public function reset(string $key): void
	{
		$this->cache->remove($this->prefix . $key);
	}
}
