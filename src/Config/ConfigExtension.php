<?php

declare(strict_types=1);

namespace ON\Config;

use Closure;
use Exception;
use Laminas\Stdlib\Glob;
use ON\Application;
use ON\Extension\AbstractExtension;
use ON\Init\InitContext;
use ON\Config\Init\ConfigInitEvents;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ReflectionFunction;

class ConfigExtension extends AbstractExtension
{
	public const ID = 'config';

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
	public function has(string $className): bool
	{
		return isset($this->configs[$className]);
	}

	public function get(?string $className = null): mixed
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

	public function start(InitContext $context): void
	{
		$context->emit(ConfigInitEvents::CONFIGURE, new ConfigConfigureEvent($this));

		$files = Glob::glob("config" . sprintf('{,/*.}{all,%s,local}.php', $_ENV['APP_ENV'] ?? 'production'), Glob::GLOB_BRACE, true);
		foreach ($files as $file) {
			$obj = include_once($file);

			if (! is_object($obj)) {
				throw new Exception(
					sprintf("Configuration file (%s) should return an object, return: %s.", $file, $obj)
				);
			} elseif ($obj instanceof Closure) {
				// in case the app wants to do some custom merging/overwritting
				$reflection = new ReflectionFunction($obj);
				$parameters = $reflection->getParameters();
				$values = [];
				foreach ($parameters as $parameter) {
					if ($parameter->getType()->getName() == get_class($this->app)) {
						$values[] = $this->app;
					} else {
						$config = $this->get($parameter->getType()->getName());
						if (! isset($config)) {
							throw new Exception("You can only get the application and config objects in configuration closures.");
						}
						$values[] = $config;
					}

				}
				$obj = call_user_func_array($obj, $values);
			} elseif ($obj instanceof Config) {
				// for 99% of cases, it's our config object and we know how to merge it
				$class_name = get_class($obj);

				if ($this->has($class_name)) {
					$this->get($class_name)
							->mergeConfig($obj);
					$obj = $this->get($class_name);
				}
			}
			if (isset($obj)) {
				$this->set(get_class($obj), $obj);
			}
		}

		$context->emit(ConfigInitEvents::READY, new ConfigReadyEvent($this));
	}
}
