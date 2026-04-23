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
use ON\Config\ConfigExtension;
use ON\Container\Executor\ExecutorFactory;
use ON\Container\Executor\ExecutorInterface;
use ON\Extension\AbstractExtension;
use ON\Config\Init\ConfigInitEvents;
use ON\Config\Init\Event\ConfigReadyEvent;
use ON\Container\Init\ContainerInitEvents;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Container\Init\Event\ContainerSetupEvent;
use ON\Init\Init;
use Psr\Container\ContainerInterface;
use function rtrim;

class ContainerExtension extends AbstractExtension
{
	public const ID = 'container';

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
	public function requires(): array
	{
		return ['config'];
	}

	public function register(Init $init): void
	{
		$init->on(ConfigInitEvents::READY, [$this, 'buildContainer']);
	}

	public function buildContainer(ConfigReadyEvent $event, \ON\Init\InitContext $context): void
	{
		$configs = $event->config->get();
		$containerConfig = $configs[ContainerConfig::class] ?? $event->config->get(ContainerConfig::class);

		$context->emit(
			ContainerInitEvents::SETUP,
			new ContainerSetupEvent($this, $event->config, $containerConfig)
		);

		$this->createConfiguredContainer($event->config);

		$context->emit(ContainerInitEvents::READY, new ContainerReadyEvent($this, $this->container));
	}

	public function createConfiguredContainer(ConfigExtension $config_ext): void
	{
		$configs = $config_ext->get();

		$this->container = $this->createContainer($configs[ContainerConfig::class]);

		foreach ($configs as $class => $config) {
			$this->container->set($class, $config);
		}

		// we need to set it to the container in case other places need the instance of Application
		$this->container->set(get_class($this->app), $this->app);

	}

	public function getContainer(): ContainerInterface
	{
		return $this->container;
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
