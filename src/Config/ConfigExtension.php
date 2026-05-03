<?php

declare(strict_types=1);

namespace ON\Config;

use Closure;
use Exception;
use Laminas\Stdlib\Glob;
use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Init\InitContext;
use ON\FS\Path;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Config\Init\Event\ConfigConfigureEvent;
use Laminas\Stdlib\ArrayUtils;
use ReflectionFunction;

class ConfigExtension extends AbstractExtension
{
	public const ID = 'config';

	public const NAMESPACE = "core.extensions.config";
	protected int $type = self::TYPE_EXTENSION;
	protected Application $app;
	protected array $options;
	protected array $data = [];
	protected array $instances = [];
	protected ?string $cachePath = null;
	protected array $cacheExceptions = [];

	public function __construct(
		Application $app,
		array $options = []
	) {
		$this->options = $options;
		$this->app = $app;
		if (! isset($this->options['debug'])) {
			$this->options['debug'] = $app->isDebug();
		}

		if (isset($options['cachePath'])) {
			$this->cachePath = Path::from($options['cachePath'], $this->app->paths->get('project'))
				->absolute();
		} else {
			$this->cachePath = $app->paths->get('cache')->append('config.php')->absolute();
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

		$env = $_ENV['APP_ENV'] ?? 'production';
		$files = Glob::glob("config" . sprintf('{,/*.}{all,%s,local}.php', $env), Glob::GLOB_BRACE, true);

		foreach ($files as $file) {
			$this->processFile($file);
		}

		$this->saveCache();

		$context->emit(new ConfigReadyEvent($this));
	}

	protected function processFile(string $file): void
	{
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
			if (! in_array($file, $this->cacheExceptions)) {
				$this->cacheExceptions[] = $file;
			}
		}
	}

	protected function loadCache(): bool
	{
		if ($this->cachePath && file_exists($this->cachePath) && !$this->options["debug"]) {
			$content = file_get_contents($this->cachePath);
			if ($content) {
				$cache = unserialize($content);
				if (is_array($cache) && isset($cache['data'])) {
					$this->data = $cache['data'];
					$this->cacheExceptions = $cache['cacheExceptions'] ?? [];
					return true;
				}
			}
		}

		return false;
	}

	protected function saveCache(): void
	{
		if (! $this->cachePath || $this->options['debug']) {
			return;
		}

		$dir = dirname($this->cachePath);
		if (! is_dir($dir)) {
			mkdir($dir, 0777, true);
		}

		$dataToCache = $this->data;
		foreach ($dataToCache as $key => $value) {
			if (is_object($value) && !($value instanceof Config)) {
				unset($dataToCache[$key]);
			}
		}

		$content = serialize([
			'data' => $dataToCache,
			'cacheExceptions' => $this->cacheExceptions
		]);
		file_put_contents($this->cachePath, $content);
	}

}
