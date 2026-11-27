<?php

declare(strict_types=1);

namespace ON\RateLimit;

use DateTimeImmutable;
use function hash;
use function max;
use ON\Cache\Cache;
use ON\Cache\CacheInterface;
use ON\Cache\SynchronizedCachePool;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use function time;

class CacheRateLimiter implements RateLimiterInterface
{
	private SynchronizedCachePool $pool;

	public function __construct(
		protected CacheInterface $cache,
		protected string $prefix = 'ratelimit_'
	) {
		$pool = $cache instanceof Cache ? $cache->getSynchronizedPool() : null;
		$this->pool = $pool ?? throw new RuntimeException(
			'CacheRateLimiter requires an enabled framework Cache backed by FilesystemAdapter or ArrayAdapter. '
			. 'Configure a custom RateLimiterInterface for other backends.'
		);
	}

	public function attempt(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$cacheKey = $this->formatKey($key);

		return $this->pool->synchronized($cacheKey, static function (CacheItemPoolInterface $pool) use ($cacheKey, $maxAttempts, $windowSeconds): RateLimitResult {
			$item = $pool->getItem($cacheKey);
			$data = $item->get();
			$now = time();
			if ($data === null || $now - $data['start'] >= $windowSeconds) {
				$data = ['count' => 0, 'start' => $now];
			}

			$end = $data['start'] + $windowSeconds;
			if ($data['count'] >= $maxAttempts) {
				return new RateLimitResult(false, 0, $maxAttempts, max(0, $end - $now));
			}

			$data['count']++;
			// Symfony rounds storage TTLs down to whole seconds. Keep the item through the logical window.
			$item->set($data)->expiresAt(new DateTimeImmutable('@' . ($end + 1)));
			if (! $pool->save($item)) {
				throw new RuntimeException('Unable to save the rate limit counter.');
			}

			return new RateLimitResult(true, $maxAttempts - $data['count'], $maxAttempts);
		});
	}

	public function peek(string $key, int $maxAttempts = 60, int $windowSeconds = 60): RateLimitResult
	{
		$cacheKey = $this->formatKey($key);

		return $this->pool->synchronized($cacheKey, static function (CacheItemPoolInterface $pool) use ($cacheKey, $maxAttempts, $windowSeconds): RateLimitResult {
			$data = $pool->getItem($cacheKey)->get();
			$now = time();
			if ($data === null || $now - $data['start'] >= $windowSeconds) {
				return new RateLimitResult(true, $maxAttempts, $maxAttempts);
			}

			if ($data['count'] >= $maxAttempts) {
				return new RateLimitResult(false, 0, $maxAttempts, $windowSeconds - ($now - $data['start']));
			}

			return new RateLimitResult(true, $maxAttempts - $data['count'], $maxAttempts);
		});
	}

	public function reset(string $key): void
	{
		$cacheKey = $this->formatKey($key);
		$this->pool->synchronized($cacheKey, static function (CacheItemPoolInterface $pool) use ($cacheKey): void {
			if (! $pool->deleteItem($cacheKey)) {
				throw new RuntimeException('Unable to reset the rate limit counter.');
			}
		});
	}

	private function formatKey(string $key): string
	{
		return hash('sha256', strlen($this->prefix) . ':' . $this->prefix . $key);
	}
}
