<?php

declare(strict_types=1);

namespace ON\Cache;

use Closure;
use DateTimeInterface;
use ON\Cache\Exception\UnableClearCacheException;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;

class Cache implements CacheInterface
{
	private AdapterInterface $rootAdapter;

	public function __construct(
		protected AdapterInterface $adapter,
		protected bool $enabled = false,
		protected string $namespace = '',
		private ?string $lockDirectory = null
	) {
		$this->rootAdapter = $adapter;
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

	public function withNamespace(string $namespace): CacheInterface
	{
		$namespace = trim($namespace);
		if ($namespace === '') {
			return $this;
		}

		$scopedNamespace = $this->namespace === ''
			? $namespace
			: $this->namespace . ':' . $namespace;

		$scoped = new self(
			new ProxyAdapter($this->adapter, $namespace),
			$this->enabled,
			$scopedNamespace,
			$this->lockDirectory
		);
		$scoped->rootAdapter = $this->rootAdapter;

		return $scoped;
	}

	public function getNamespace(): string
	{
		return $this->namespace;
	}

	/** @internal */
	public function getSynchronizedPool(): ?SynchronizedCachePool
	{
		if (! $this->isEnabled()) {
			return null;
		}

		return match (true) {
			$this->rootAdapter instanceof ArrayAdapter => new SynchronizedCachePool($this->adapter, null),
			$this->rootAdapter instanceof FilesystemAdapter => new SynchronizedCachePool(
				$this->adapter,
				$this->lockDirectory ?? SynchronizedCachePool::defaultLockDirectory()
			),
			default => null,
		};
	}
}
