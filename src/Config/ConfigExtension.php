<?php

declare(strict_types=1);

namespace ON\Config;

use Closure;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Stdlib\Glob;
use ON\Application;
use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Extension\AbstractExtension;
use ON\FS\Path;
use ON\Init\Attribute\On;
use ON\Init\InitContext;
use ReflectionFunction;
use RuntimeException;
use stdClass;
use Throwable;
use UnitEnum;

class ConfigExtension extends AbstractExtension
{
	public const ID = 'config';

	protected array $data = [];
	protected array $instances = [];
	protected ?string $cachePath = null;
	protected array $cacheExceptions = [];

	public function __construct(
		Application $app,
		array $options = []
	) {
		parent::__construct($app, $options);
		if (! isset($this->options['debug'])) {
			$this->options['debug'] = $app->isDebug();
		}

		if (isset($options['cachePath'])) {
			$this->cachePath = Path::from($options['cachePath'], $this->app->paths->get('project'))
				->getAbsolutePath();
		} else {
			$this->cachePath = $app->paths->get('cache')->append('config.php')->getAbsolutePath();
		}
	}

	public function get(?string $className = null): mixed
	{
		if ($className === null) {
			// Return all configs as objects where possible to maintain backward compatibility
			foreach (array_keys($this->data) as $key) {
				if (! isset($this->instances[$key]) && class_exists($key) && is_subclass_of($key, Config::class)) {
					$this->get($key);
				}
			}

			$data = array_filter(
				$this->data,
				static fn (mixed $value): bool => ! is_object($value) || $value instanceof Config
			);
			$instances = array_filter(
				$this->instances,
				static fn (mixed $value): bool => $value instanceof Config
			);

			return array_merge($data, $instances);
		}

		if (! isset($this->instances[$className])) {
			if (isset($this->data[$className]) && is_object($this->data[$className])) {
				$this->instances[$className] = $this->data[$className];

				return $this->instances[$className];
			}

			if (! class_exists($className)) {
				return $this->data[$className] ?? null;
			}

			$instance = new $className();

			if (! isset($this->data[$className])) {
				if ($instance instanceof Config) {
					$this->data[$className] = $instance->all();
				} else {
					$this->data[$className] = $instance;
				}
			}

			if ($instance instanceof Config) {
				$instance->setReference($this->data[$className]);
			}
			$this->instances[$className] = $instance;
		}

		return $this->instances[$className];
	}

	public function has(string $className): bool
	{
		return isset($this->data[$className]);
	}

	public function getCachePath(): ?string
	{
		return $this->cachePath;
	}

	public function set(string $className, mixed $obj)
	{
		if ($obj instanceof Config) {
			$this->data[$className] = $obj->all();
			$this->instances[$className] = $obj;
			$this->instances[$className]->setReference($this->data[$className]);
		} else {
			$this->data[$className] = $obj;
			if (is_object($obj)) {
				$this->instances[$className] = $obj;
			}
		}
	}

	public function start(InitContext $context): void
	{
		if ($this->loadCache()) {
			foreach ($this->cacheExceptions as $file) {
				$this->processFile($file);
			}
			$context->emit(new ConfigReadyEvent($this));

			return;
		}

		$context->emit(new ConfigConfigureEvent($this));

		$directory = $this->app->paths->get('config')->getAbsolutePath();
		// Glob patterns use forward slashes and match the configured directory literally.
		$literalGlob = ['[' => '[[]', ']' => '[]]', '*' => '[*]', '?' => '[?]'];
		$directory = rtrim(strtr(str_replace('\\', '/', $directory), $literalGlob), '/');
		foreach (array_unique(['all', $_ENV['APP_ENV'] ?? 'production', 'local']) as $env) {
			$suffix = strtr($env, $literalGlob);
			foreach (Glob::glob("{$directory}/*.{$suffix}.php") as $file) {
				if (is_file($file)) {
					$this->processFile($file);
				}
			}
		}

		$this->saveCache();

		$context->emit(new ConfigReadyEvent($this));
	}

	protected function processFile(string $file): void
	{
		$file = Path::from($file, $this->app->paths->get('project'))->getAbsolutePath();
		$obj = include $file;

		if ($obj instanceof Closure) {
			$reflection = new ReflectionFunction($obj);
			$parameters = $reflection->getParameters();
			$values = [];
			foreach ($parameters as $parameter) {
				$type = $parameter->getType();
				if ($type && $type->getName() == get_class($this->app)) {
					$values[] = $this->app;
				} elseif ($type) {
					$typeName = $type->getName();
					$values[] = $this->get($typeName);
				}
			}
			$obj = call_user_func_array($obj, $values);
		}

		if (is_array($obj)) {
			$this->data = ArrayUtils::merge($this->data, $obj);

			return;
		}

		if ($obj instanceof Config) {
			$className = get_class($obj);
			$this->get($className)->mergeConfig($obj);

			return;
		}

		if (is_object($obj)) {
			$className = get_class($obj);
			$this->data[$className] = $obj;
			$this->instances[$className] = $obj;
			if (! in_array($file, $this->cacheExceptions, true)) {
				$this->cacheExceptions[] = $file;
			}
		}
	}

	protected function loadCache(): bool
	{
		if ($this->cachePath && is_file($this->cachePath) && ! $this->options["debug"]) {
			$prefix = @file_get_contents($this->cachePath, false, null, 0, 6);
			if ($prefix !== "<?php\n") {
				return false;
			}

			try {
				$cache = @include $this->cachePath;
			} catch (Throwable) {
				return false;
			}
			if (is_array($cache) && isset($cache['data']) && is_array($cache['data'])) {
				$this->data = $cache['data'];
				$this->cacheExceptions = isset($cache['cacheExceptions']) && is_array($cache['cacheExceptions'])
					? $cache['cacheExceptions']
					: [];

				return true;
			}
		}

		return false;
	}

	#[On(optional: true)]
	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'config',
			label: 'Config',
			clear: function (): void {
				CachePathCleaner::removeFile($this->getCachePath());
			},
			priority: 95,
			description: 'Clears cached application configuration.'
		));
	}

	protected function saveCache(): void
	{
		if (! $this->cachePath) {
			return;
		}

		$dataToCache = $this->data;
		foreach ($dataToCache as $key => $value) {
			if (is_object($value) && ! ($value instanceof Config)) {
				unset($dataToCache[$key]);
			}
		}

		$skipped = [];
		$this->findNonExportableValues($dataToCache, 'data', $skipped);
		if ($skipped !== []) {
			// Incomplete caches skip ConfigConfigureEvent on the next boot. Never write
			// a partial snapshot — fail hard so Closures cannot hide in production.
			throw new RuntimeException(sprintf(
				'Config cache cannot be written; non-serializable values at: %s. Use class-based factories/aliases instead of Closures.',
				implode(', ', $skipped)
			));
		}

		// Debug mode always uses a cold config path; still validate above so Closures fail loudly.
		if ($this->options['debug']) {
			return;
		}

		$dir = dirname($this->cachePath);
		if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
			throw new RuntimeException(sprintf('Unable to create config cache directory "%s".', $dir));
		}

		try {
			$content = "<?php\n\nreturn " . var_export([
				'data' => $dataToCache,
				'cacheExceptions' => $this->cacheExceptions,
			], true) . ";\n";
		} catch (Throwable $e) {
			throw new RuntimeException('Config cache write failed: ' . $e->getMessage(), 0, $e);
		}

		$tmpFile = $this->cachePath . '.' . uniqid('', true) . '.tmp';
		if (@file_put_contents($tmpFile, $content, LOCK_EX) !== strlen($content)) {
			@unlink($tmpFile);

			throw new RuntimeException(sprintf('Unable to write temporary config cache file "%s".', $tmpFile));
		}

		if (! @rename($tmpFile, $this->cachePath)) {
			@unlink($tmpFile);

			throw new RuntimeException(sprintf('Unable to move config cache file into place at "%s".', $this->cachePath));
		}

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->cachePath, true);
		}
	}

	/**
	 * @param list<string> $skipped
	 */
	private function findNonExportableValues(mixed $value, string $path, array &$skipped, int $depth = 0): void
	{
		if (is_resource($value) || $depth > 256) {
			$skipped[] = $path;

			return;
		}

		if ($value instanceof UnitEnum) {
			return;
		}

		if (is_object($value)) {
			if (! $value instanceof stdClass && ! is_callable([$value::class, '__set_state'])) {
				$skipped[] = $path;

				return;
			}
			$value = (array) $value;
		}

		if (is_array($value)) {
			foreach ($value as $key => $child) {
				$name = str_replace("\0", ' ', (string) $key);
				$this->findNonExportableValues($child, "{$path}[{$name}]", $skipped, $depth + 1);
			}
		}
	}
}
