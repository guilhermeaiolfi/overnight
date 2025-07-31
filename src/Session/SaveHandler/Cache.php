<?php

declare(strict_types=1);

namespace ON\Session\SaveHandler;

use Laminas\Cache\Storage\ClearExpiredInterface as ClearExpiredCacheStorage;
use Laminas\Cache\Storage\StorageInterface as CacheStorage;
use Laminas\Session\SaveHandler\SaveHandlerInterface;
use ON\Cache\Cache as OnCache;

/**
 * Cache session save handler
 */
class Cache implements SaveHandlerInterface
{
	/**
	 * Session Save Path
	 */
	protected string $sessionSavePath;

	/**
	 * Session Name
	 */
	protected $sessionName;

	/**
	 * The cache storage
	 */
	protected $cacheStorage;

	/**
	 * Constructor
	 */
	public function __construct(OnCache $cache)
	{
		$this->setCacheStorage($cache);
	}

	/**
	 * Open Session
	 */
	public function open(string $path, string $name): bool
	{
		// @todo figure out if we want to use these
		$this->sessionSavePath = $path;
		$this->sessionName = $name;

		return true;
	}

	/**
	 * Close session
	 */
	public function close(): bool
	{
		return true;
	}

	/**
	 * Read session data
	 */
	public function read(string $id): string
	{
		return (string) $this->getCacheStorage()->getItem($id);
	}

	/**
	 * Write session data
	 */
	public function write(string $id, string $data): bool
	{
		return $this->getCacheStorage()->setItem($id, $data);
	}

	/**
	 * Destroy session
	 */
	public function destroy(string $id): bool
	{
		$this->getCacheStorage()->getItem($id, $exists);
		if (! (bool) $exists) {
			return true;
		}

		return (bool) $this->getCacheStorage()->removeItem($id);
	}

	/**
	 * Garbage Collection
	 */
	public function gc(int $maxlifetime): bool
	{
		$cache = $this->getCacheStorage();
		if ($cache instanceof ClearExpiredCacheStorage) {
			return $cache->clearExpired();
		}

		return true;
	}

	/**
	 * Set cache storage
	*/
	public function setCacheStorage(CacheStorage $cacheStorage): self
	{
		$this->cacheStorage = $cacheStorage;

		return $this;
	}

	/**
	 * Get cache storage
	 *
	 * @return CacheStorage
	 */
	public function getCacheStorage(): CacheStorage
	{
		return $this->cacheStorage;
	}
}
