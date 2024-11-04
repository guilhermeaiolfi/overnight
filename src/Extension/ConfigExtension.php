<?php

declare(strict_types=1);

namespace ON\Extension;

use Exception;
use Laminas\Stdlib\Glob;
use ON\Application;

class ConfigExtension extends AbstractExtension
{
	public const NAMESPACE = "core.extensions.config";
	protected int $type = self::TYPE_EXTENSION;
	protected Application $app;
	protected array $options;
	protected array $configs = [];

	public function __construct(
		Application $app,
		array $options = []
	) {
		$this->options = $options;
		$this->app = $app;
		if (! isset($this->options['debug'])) {
			$this->options['debug'] = $app->isDebug();
		}
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);
		$app->registerExtension('config', $extension);
		$app->config = $extension;

		return $extension;
	}

	public function has(string $className): bool
	{
		return isset($this->configs[$className]);
	}

	public function get(string $className = null): mixed
	{
		if ($className === null) {
			return $this->configs;
		}

		if (! isset($this->configs[$className])) {
			$this->configs[$className] = new $className();
		}

		return $this->configs[$className];
	}

	public function set(string $className, mixed $obj)
	{
		$this->configs[$className] = $obj;
	}

	public function setup(int $counter): bool
	{
		if ($counter == 0) {
			// we need to give a chance to extensions to register configs before application code
			$this->app->events->dispatch("core.extensions.config.setup", $this);

			return false;
		}
		$files = Glob::glob("config" . sprintf('{,/*.}{all,%s,local}.php', $_ENV['APP_ENV'] ?? 'production'), Glob::GLOB_BRACE, true);
		foreach ($files as $file) {
			$obj = include_once($file);

			if (! is_object($obj)) {
				throw new Exception(
					sprintf("Configuration file (%s) should return an object, return: %s.", $file, $obj)
				);
			} else {
				$class_name = get_class($obj);

				if ($this->has($class_name)) {
					$this->get($class_name)
						 ->mergeConfigArray($obj);
				} else {
					$this->set($class_name, $obj);
				}
			}
		}

		return true;
	}

	public function ready()
	{
		$this->app->events->dispatch("core.extensions.config.ready", $this);
	}
}
