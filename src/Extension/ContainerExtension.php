<?php

namespace ON\Extension;

use DI\ContainerBuilder;
use Exception;
use Laminas\Stratigility\Middleware\ErrorHandler;
use ON\Application;
use ON\Config\ConfigInterface;
use ON\Config\ContainerConfig;
use ON\Container\ConfigDefinitionSource;
use Psr\Container\ContainerInterface;

use function DI\autowire;
use function DI\create;
use function DI\factory;
use function DI\get;
use function is_array;
use function is_file;
use function is_numeric;
use function is_object;
use function rtrim;

class ContainerExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    protected $container;

    protected string $cache_path;
    protected array $definitions;

    /**
     * Make overridden delegator idempotent
     */
    private int $delegatorCounter = 0;

    public function __construct(
        protected Application $app,
        protected $options = []
    ) {

    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        $app->registerExtension('container', $extension);
        return $extension;
    }

    public function setup(int $counter): bool
    {
        if ($this->app->isExtensionReady('config')) {
            // we need to get the container working here (and not in the setup()),
            // because it may be used in the setup method by other extensions
            
            /** @var \ON\Extension\ConfigExtension $config_ext */
            $config_ext = $this->app->getExtension('config');
                        
            $configs = $config_ext->get();

            $this->container = $container = $this->createContainer($configs[ContainerConfig::class]);
            
            Application::setContainer($container);
            foreach ($configs as $class => $config) {
                $container->set($class, $config);
            }
            /*
            $container->set(ConfigInterface::class, $this->app->ext('config')->getConfig());*/

            //echo VarExporter::export($container, VarExporter::ADD_RETURN | VarExporter::CLOSURE_SNAPSHOT_USES);exit;
            // we need to set it to the container in case other places need the instance of Application
            $container->set(get_class($this->app), $this->app);
            /*if ($config->get("errors") && $this->container->has(ErrorHandler::class)) {
                //creates the error handler early on so anything gets handled
                $this->container->get(ErrorHandler::class);
    
                //var_dump($config->get('templates.paths'));
                return true;
            }*/
            //throw new Exception("ON doesn't know how create ErrorHandler class. Configure it in the DI config.");
            return true;
        }
        return false;
    }
    
    public function hasCache()
    {
        return is_file(rtrim($this->cache_path, '/') . '/CompiledContainer.php');
    }

    public function createContainer($config) {

        $builder = new ContainerBuilder();
        
        $this->cache_path = $this->options["cache_path"]?? $config->get('cache_path', "var/container/");

        if ($config->get("enable_cache", false))
        {
            $builder->enableCompilation($this->cache_path);
        }
        
        $write_proxies_to_file = $config->get("write_proxies", true);
        $builder->writeProxiesToFile($write_proxies_to_file, $this->cache_path);
        

        if (!$this->hasCache())
        {
            // lets build the container
            //$this->dependencies = $config->get("dependencies", []);

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
                    $previous                     = $name . '-' . ++$this->delegatorCounter;
                    $this->definitions[$previous] = $this->definitions[$name];
                    $this->definitions[$name]     = $this->createDelegatorFactory($delegator, $previous, $name);
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

        $container->set('config', $config);

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
            $factory  = new $delegator();
            $callable = function () use ($previous, $container) {
                return $container->get($previous);
            };
            return $factory($container, $name, $callable);
        })->parameter('delegator', $delegator)
          ->parameter('previous', $previous)
          ->parameter('name', $name);
    }
}
