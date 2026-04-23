<?php

declare(strict_types=1);

namespace ON\Cache;

use Closure;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

interface CacheInterface
{
	public function put(string $key, mixed $value, ?DateTimeInterface $expiresAt = null): CacheItemInterface;

	public function get(string $key): mixed;

	/** @param Closure(): mixed $cache */
	public function resolve(string $key, Closure $cache, ?DateTimeInterface $expiresAt = null): mixed;

	public function remove(string $key): bool;

	public function clear(): void;

	public function isEnabled(): bool;
}
