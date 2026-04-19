<?php

declare(strict_types=1);

namespace ON\Container;

use function DI\autowire;
use DI\ContainerBuilder;
use function DI\create;
use DI\Definition\Helper\DefinitionHelper;
use function DI\factory;
use function DI\get;
use function is_file;
use function is_numeric;
use function is_object;
use ON\Application;
use ON\Container\Executor\ExecutorFactory;
use ON\Container\Executor\ExecutorInterface;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use Psr\Container\ContainerInterface;
use function rtrim;

class ContainerExtension extends AbstractExtension
{
	public const NAMESPACE = "core.extensions.container";
	protected int $type = self::TYPE_EXTENSION;
	protected $container;

	protected string $cache_path;
	protected array $definitions;

	private int $delegatorCounter = 0;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);
		$app->registerExtension('container', $extension);

		return $extension;
	}

	public function boot(): void
	{
		$this->app->ext('config')->when('ready', [$this, 'buildContainer']);
	}

	public function buildContainer(): void
	{

		// Let extensions mutate ContainerConfig before PHP-DI consumes it.
		$this->dispatchStateChange('setup');

		$this->when('setup', [$this, 'createConfiguredContainer']);
	}

	public function createConfiguredContainer(self $extension = null, mixed $data = null): void
	{
		/** @var ConfigExtension $config_ext */
		$config_ext = $this->app->config;

		$configs = $config_ext->get();

		$this->app->container = $this->container = $this->createContainer($configs[ContainerConfig::class]);

		foreach ($configs as $class => $config) {
			$this->container->set($class, $config);
		}

		// we need to set it to the container in case other places need the instance of Application
		$this->container->set(get_class($this->app), $this->app);

		// finally we can say it's ready
		$this->dispatchStateChange('ready');
	}

	public function hasCache()
	{
		return is_file(rtrim($this->cache_path, '/') . '/CompiledContainer.php');
	}

	public function createContainer($config)
	{

		$builder = new ContainerBuilder();

		$this->cache_path = $this->options["cache_path"] ?? $config->get('cache_path', "var/container/");

		if ($config->get("enable_cache", false)) {
			$builder->enableCompilation($this->cache_path);
		}

		$write_proxies_to_file = $config->get("write_proxies", true);
		$builder->writeProxiesToFile($write_proxies_to_file, $this->cache_path);


		if (! $this->hasCache()) {
			// lets build the container

			// default dependencies
			$this->definitions[ExecutorInterface::class] = factory(ExecutorFactory::class);

			foreach ($config->get('definitions.services', []) as $name => $service) {
				$this->definitions[$name] = is_object($service) ? $service : create($service);
			}

			foreach ($config->get('definitions.factories', []) as $name => $factory) {
				$this->definitions[$name] = factory($factory);
			}


			foreach ($config->get('definitions.invokables', []) as $key => $object) {
				$name = is_numeric($key) ? $object : $key;
				$this->definitions[$name] = create($object);
				if ($name !== $object) {
					// create an alias to the service itself
					$this->definitions[$object] = get($name);
				}
			}

			foreach ($config->get('definitions.autowires', []) as $name) {
				$this->definitions[$name] = autowire($name);
			}

			foreach ($config->get('definitions.aliases', []) as $alias => $target) {
				$this->definitions[$alias] = get((string) $target);
			}

			foreach ($config->get('definitions.delegators', []) as $name => $delegators) {
				foreach ($delegators as $delegator) {
					$previous = $name . '-' . ++$this->delegatorCounter;
					$this->definitions[$previous] = $this->definitions[$name];
					$this->definitions[$name] = $this->createDelegatorFactory($delegator, $previous, $name);
				}
			}

			$builder->addDefinitions($this->definitions);

			$builder->addDefinitions(
				new ConfigDefinitionSource(
					$config
				)
			);
		}

		// default autowire is true
		$autowire = $config->get("autowire", true);
		$builder->useAutowiring($autowire);

		if ($config->get('definition_cache', false)) {
			$builder->enableDefinitionCache();
		}

		// default autowire is true
		$use_attributes = $config->get("use_attributes", true);
		$builder->useAttributes($use_attributes);

		$container = $builder->build();

		return $container;
	}

	private function createDelegatorFactory(string $delegator, string $previous, string $name): DefinitionHelper
	{
		return factory(function (
			ContainerInterface $container,
			string $delegator,
			string $previous,
			string $name
		) {
			$factory = new $delegator();
			$callable = function () use ($previous, $container) {
				return $container->get($previous);
			};

			return $factory($container, $name, $callable);
		})->parameter('delegator', $delegator)
		  ->parameter('previous', $previous)
		  ->parameter('name', $name);
	}
}
