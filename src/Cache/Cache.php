<?php

declare(strict_types=1);

namespace ON\Cache;

use Closure;
use DateTimeInterface;
use ON\Cache\Exception\UnableClearCacheException;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class Cache implements CacheInterface
{
	public function __construct(
		protected AdapterInterface $adapter,
		protected bool $enabled = false
	) {

	}

	public function remove(string $key): bool
	{
		if (! $this->isEnabled()) {
			return false;
		}

		return $this->adapter->deleteItem($key);
	}

	public function delete(string $key): bool
	{
		return $this->remove($key);
	}

	public function get(string $key): mixed
	{
		if (! $this->isEnabled()) {
			return null;
		}

		return $this->adapter->getItem($key)->get();
	}

	/** @param Closure(): mixed $cache */
	public function resolve(string $key, Closure $cache, ?DateTimeInterface $expiresAt = null): mixed
	{
		if (! $this->isEnabled()) {
			return $cache();
		}
		$item = $this->adapter->getItem($key);

		if (! $item->isHit()) {
			$item = $this->put($key, $cache(), $expiresAt);
		}

		return $item->get();
	}

	public function put(string $key, mixed $value, ?DateTimeInterface $expiresAt = null): CacheItemInterface
	{
		$item = $this->adapter
			->getItem($key)
			->set($value);

		if ($expiresAt !== null) {
			$item = $item->expiresAt($expiresAt);
		}

		if ($this->isEnabled()) {
			$this->adapter->save($item);
		}

		return $item;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function clear(): void
	{
		if (! $this->adapter->clear()) {
			throw new UnableClearCacheException();
		}
	}
}
