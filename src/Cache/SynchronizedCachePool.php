<?php

declare(strict_types=1);

namespace ON\Cache;

use Closure;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

/** @internal */
final class SynchronizedCachePool
{
	public function __construct(
		private CacheItemPoolInterface $pool,
		private ?string $lockDirectory
	) {
	}

	public static function defaultLockDirectory(): string
	{
		$suffix = function_exists('posix_geteuid') ? '-' . posix_geteuid() : '';

		return sys_get_temp_dir() . '/overnight-cache-locks' . $suffix;
	}

	/**
	 * @template T
	 *
	 * @param Closure(CacheItemPoolInterface): T $operation
	 *
	 * @return T
	 */
	public function synchronized(string $key, Closure $operation): mixed
	{
		if ($this->lockDirectory === null) {
			// ArrayAdapter stores its data in the PHP process and performs no I/O.
			return $operation($this->pool);
		}

		$directory = $this->lockDirectory;
		if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Unable to create cache lock directory "%s".', $directory));
		}

		// Use a fixed set of lock files. Unlinking a lock file could split its waiters across inodes.
		$path = $directory . '/' . substr(hash('sha256', $key), 0, 2) . '.lock';
		$lock = @fopen($path, 'c');
		if ($lock === false) {
			throw new RuntimeException(sprintf('Unable to open cache lock "%s".', $path));
		}

		try {
			if (! flock($lock, LOCK_EX)) {
				throw new RuntimeException(sprintf('Unable to acquire cache lock "%s".', $path));
			}

			return $operation($this->pool);
		} finally {
			fclose($lock);
		}
	}
}
