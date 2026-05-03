<?php

declare(strict_types=1);

namespace ON\Extension;

use FilesystemIterator;
use ON\Application;
use ON\Discovery\ClassFinder;
use ON\FS\Path;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

class AutoWiringExtension extends AbstractExtension
{
	public const ID = 'autowiring';

	public const NAMESPACE = "core.extensions.autowiring";

	protected int $type = self::TYPE_EXTENSION;

	private const DEFAULT_SCAN_PATH = 'modules';

	private const DEFAULT_CACHE_MODE = 'auto';

	private const DEFAULT_CACHE_FILE = 'autowiring.php';

	private string $cacheFile;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		$app->autowiring = $this;
		$this->cacheFile = $this->app->paths->get('cache')->append($options["cache_file"] ?? self::DEFAULT_CACHE_FILE)->absolute();
		$this->installDiscoveredExtensions();
	}
	public function start(\ON\Init\InitContext $context): void
	{
	}

	private function installDiscoveredExtensions(): void
	{
		foreach ($this->discoverExtensions() as $extensionClass => $filepath) {
			if (! class_exists($extensionClass, false)) {
				require_once $filepath;
			}

			$options = $this->optionsFor($extensionClass);
			$this->app->install($extensionClass, $options);
		}
	}

	/**
	 * @return array<class-string<ExtensionInterface>, string>
	 */
	private function discoverExtensions(): array
	{
		$paths = $this->scanPaths();
		$cacheKey = $this->cacheKey($paths);

		if ($this->shouldUseCache()) {
			$cached = $this->readCache($cacheKey);
			if ($cached !== null) {
				return $cached;
			}
		}

		$finder = new ClassFinder();
		$extensions = [];

		foreach ($paths as $path) {
			if (! is_dir($path)) {
				continue;
			}

			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
			);

			foreach ($files as $file) {
				if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
					continue;
				}

				$filepath = $file->getPathname();
				if ($this->isExcluded($filepath)) {
					continue;
				}

				$classes = $finder->getClassesInFile($filepath);
				if ($classes === []) {
					continue;
				}

				foreach ($classes as $class) {
					if ($this->isExcluded($class) || ! $this->isInstallableExtension($class, $filepath)) {
						continue;
					}

					$extensions[$class] = $filepath;
				}
			}
		}

		if ($this->shouldUseCache()) {
			$this->writeCache($cacheKey, $extensions);
		}

		return $extensions;
	}

	/**
	 * @return list<string>
	 */
	private function scanPaths(): array
	{
		$paths = $this->options['scan_paths']
			?? $this->options['paths']
			?? $this->options['scan_path']
			?? $this->options['path']
			?? self::DEFAULT_SCAN_PATH;

		$paths = is_array($paths) ? $paths : [$paths];

		return array_values(array_map([$this, 'absolutePath'], $paths));
	}

	private function absolutePath(string $path): string
	{
		return rtrim(Path::from($path, $this->app->paths->get('project'))->absolute(), DIRECTORY_SEPARATOR);
	}

	/**
	 * @return list<string>
	 */
	private function excludePatterns(): array
	{
		$patterns = $this->options['exclude'] ?? $this->options['excludes'] ?? [];

		return is_array($patterns) ? array_values($patterns) : [$patterns];
	}

	private function isExcluded(string $value): bool
	{
		$value = str_replace('\\', '/', $value);

		foreach ($this->excludePatterns() as $pattern) {
			$pattern = str_replace('\\', '/', $pattern);
			if ($this->matchesPattern($pattern, $value)) {
				return true;
			}
		}

		return false;
	}

	private function matchesPattern(string $pattern, string $value): bool
	{
		$regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

		return preg_match($regex, $value) === 1;
	}

	/**
	 * @param class-string $class
	 */
	private function isInstallableExtension(string $class, string $filepath): bool
	{
		if (! class_exists($class, false)) {
			require_once $filepath;
		}

		if (! class_exists($class)) {
			return false;
		}

		$reflection = new ReflectionClass($class);

		return ! $reflection->isAbstract()
			&& $reflection->implementsInterface(ExtensionInterface::class);
	}

	/**
	 * @param class-string<ExtensionInterface> $extensionClass
	 * @return array<string, mixed>
	 */
	private function optionsFor(string $extensionClass): array
	{
		$options = $this->options['extensions'][$extensionClass] ?? [];

		return is_array($options) ? $options : [];
	}

	/**
	 * @param list<string> $paths
	 */
	private function cacheKey(array $paths): string
	{
		return sha1(json_encode([
			'paths' => $paths,
			'exclude' => $this->excludePatterns(),
		]) ?: '');
	}

	private function shouldUseCache(): bool
	{
		$mode = $this->options['cache'] ?? self::DEFAULT_CACHE_MODE;

		if ($mode === self::DEFAULT_CACHE_MODE) {
			return ! $this->app->isDebug();
		}

		return (bool) $mode;
	}

	/**
	 * @return array<class-string<ExtensionInterface>, string>|null
	 */
	private function readCache(string $cacheKey): ?array
	{
		if (! is_file($this->cacheFile)) {
			return null;
		}

		$cache = include $this->cacheFile;
		if (! is_array($cache) || ! isset($cache[$cacheKey]) || ! is_array($cache[$cacheKey])) {
			return null;
		}

		foreach ($cache[$cacheKey] as $filepath) {
			if (! is_string($filepath) || ! is_file($filepath)) {
				return null;
			}
		}

		return $cache[$cacheKey];
	}

	/**
	 * @param array<class-string<ExtensionInterface>, string> $extensions
	 */
	private function writeCache(string $cacheKey, array $extensions): void
	{
		$cacheDir = dirname($this->cacheFile);
		if (! is_dir($cacheDir)) {
			mkdir($cacheDir, 0777, true);
		}

		$cache = is_file($this->cacheFile) ? include $this->cacheFile : [];
		if (! is_array($cache)) {
			$cache = [];
		}

		$cache[$cacheKey] = $extensions;

		file_put_contents($this->cacheFile, "<?php\n\nreturn " . var_export($cache, true) . ";\n");
	}
}
