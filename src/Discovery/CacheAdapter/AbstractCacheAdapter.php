<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use __PHP_Incomplete_Class;
use InvalidArgumentException;
use ON\Application;
use ON\Config\AppConfig;
use ON\Console\Attribute\ConsoleCommand;
use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryItem;
use ON\Discovery\DiscoveryItems;
use ON\Discovery\DiscoveryLocation;
use ON\Event\Attribute\EventHandler;
use ON\FS\Path;
use ON\Router\Attribute\Route;
use RuntimeException;
use SplObjectStorage;
use Throwable;

abstract class AbstractCacheAdapter implements CacheAdapterInterface
{
	/** @var list<class-string> */
	protected const DEFAULT_ALLOWED_CLASSES = [
		DiscoveryItems::class,
		DiscoveryItem::class,
		DiscoveryLocation::class,
		Route::class,
		EventHandler::class,
		ConsoleCommand::class,
	];

	/** @var array<string, true> */
	protected array $unreadableCacheFiles = [];

	public function __construct(
		protected AppConfig $appCfg,
		protected Application $app
	) {

	}

	abstract public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface;

	/** @return list<string> */
	protected function resolveLocationPatterns(DiscoveryLocation $location): array
	{
		$project = rtrim(str_replace('\\', '/', $this->app->paths->get('project')->getAbsolutePath()), '/');
		$globProject = DIRECTORY_SEPARATOR === '\\'
			? strtr($project, ['[' => '[[]', ']' => '[]]'])
			: addcslashes($project, '*?[]{}');
		$patterns = [];
		foreach ($location->pattern as $pattern) {
			if ($pattern === '' || str_contains($pattern, '://')) {
				$patterns[] = $pattern;

				continue;
			}

			if (DIRECTORY_SEPARATOR === '\\') {
				$pattern = str_replace('\\', '/', $pattern);
			}
			if (Path::isAbsoluteString($pattern)) {
				$patterns[] = $pattern;

				continue;
			}

			$directory = $project . '/' . $pattern;
			$patterns[] = $project !== $globProject && ! is_dir($directory) ? $globProject . '/' . $pattern : $directory;
		}

		return $patterns;
	}

	public function clear(?DiscoverInterface $discover = null, ?DiscoveryLocation $location = null): void
	{
		if ($discover === null || $location === null) {
			// Cannot determine cache file without both discover and location
			return;
		}

		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);

		if (is_file($cacheFile)) {
			@unlink($cacheFile);
		}
	}

	/**
	 * @return list<class-string>
	 */
	protected function getAllowedClasses(): array
	{
		$extra = $this->appCfg->get('discovery.cache_allowed_classes', []);
		if (! is_array($extra)) {
			$extra = [];
		}

		$extra = array_values(array_filter(
			$extra,
			static fn (mixed $class): bool => is_string($class) && $class !== ''
		));

		return array_values(array_unique([
			...self::DEFAULT_ALLOWED_CLASSES,
			...$extra,
		]));
	}

	protected function readCacheFile(string $cacheFile): ?DiscoveryItems
	{
		if (! is_file($cacheFile)) {
			return null;
		}

		$raw = @file_get_contents($cacheFile);
		if ($raw === false || $raw === '') {
			$this->unreadableCacheFiles[$cacheFile] = true;

			return null;
		}

		try {
			$data = @unserialize($raw, ['allowed_classes' => $this->getAllowedClasses()]);
			if ($data instanceof DiscoveryItems && $this->isRestored($data, new SplObjectStorage())) {
				return $data;
			}
		} catch (Throwable) {
			// A stale or incomplete cache will be rebuilt from discovery.
		}

		$this->unreadableCacheFiles[$cacheFile] = true;

		return null;
	}

	private function isRestored(mixed $value, SplObjectStorage $seen, int $depth = 0): bool
	{
		if ($value instanceof __PHP_Incomplete_Class || $depth > 64) {
			return false;
		}

		if (is_object($value)) {
			if ($seen->contains($value)) {
				return true;
			}

			$seen->attach($value);
			$value = (array) $value;
		}

		if (is_array($value)) {
			foreach ($value as $child) {
				if (! $this->isRestored($child, $seen, $depth + 1)) {
					return false;
				}
			}
		}

		return true;
	}

	protected function writeCacheFile(string $cacheFile, DiscoveryItems $data): void
	{
		$cacheDir = dirname($cacheFile);
		if (! is_dir($cacheDir) && ! @mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
			throw new RuntimeException(sprintf('Unable to create discovery cache directory "%s".', $cacheDir));
		}

		$tmpFile = $cacheFile . '.' . uniqid('', true) . '.tmp';
		$content = serialize($data);
		if (@file_put_contents($tmpFile, $content, LOCK_EX) !== strlen($content)) {
			@unlink($tmpFile);

			throw new RuntimeException(sprintf('Unable to write temporary discovery cache file "%s".', $tmpFile));
		}

		if (! @rename($tmpFile, $cacheFile)) {
			@unlink($tmpFile);

			throw new RuntimeException(sprintf('Unable to move discovery cache file into place at "%s".', $cacheFile));
		}

		unset($this->unreadableCacheFiles[$cacheFile]);
	}

	protected function cacheFilenameFromDiscover(DiscoverInterface $discover, DiscoveryLocation $location): string
	{
		$basePath = $this->appCfg->get("discovery.cache_path");
		if (! is_string($basePath) || trim($basePath) === '') {
			$basePath = $this->app->paths->get('cache')->append('discovery')->getAbsolutePath();
		} else {
			$basePath = Path::from($basePath, $this->app->paths->get('project'))
				->getAbsolutePath();
		}

		return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $this->classNameToFilename(get_class($discover), $location);
	}

	protected function classNameToFilename(string $className, DiscoveryLocation $location): string
	{
		$safeName = $this->sanitizeLocationName($location->name);
		$filename = str_replace([' ', '\\'], '_', $className);
		$filename .= "." . $safeName;
		$filename .= '.cache.php';

		return $filename;
	}

	protected function sanitizeLocationName(string $name): string
	{
		if (
			trim($name) === ''
			|| $name === '.'
			|| str_contains($name, '/')
			|| str_contains($name, '\\')
			|| str_contains($name, '..')
			|| str_contains($name, "\0")
			|| preg_match('/[\x00-\x1f]/', $name) === 1
		) {
			throw new InvalidArgumentException(sprintf(
				'Invalid discovery location name "%s": path separators and traversal segments are not allowed.',
				$name
			));
		}

		return $name;
	}
}
