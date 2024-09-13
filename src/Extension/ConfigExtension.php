<?php

namespace ON\Extension;

use Exception;
use ON\Application;

use Laminas\ConfigAggregator\ArrayProvider;
use ON\Config\ConfigAggregator;
use Laminas\ConfigAggregator\PhpFileProvider;
use Adbar\Dot;
use ON\Config\OwnParameterPostProcessor;
use ON\Event\EventSubscriberInterface;

// To enable or disable caching, set the `ConfigAggregator::ENABLE_CACHE` boolean in
// `config/autoload/framework.global.php`.
class ConfigExtension extends AbstractExtension implements EventSubscriberInterface
{
    protected int $type = self::TYPE_EXTENSION;
    protected Dot $config;
    protected Application $app;
    protected array $options;
    protected ?bool $has_cache = null;
    protected ConfigAggregator $aggregator;
    public function __construct(
        Application $app,
        array $options = []
    ) {
        $this->options = $options;
        $this->app = $app;
        if (!isset($this->options["config_file"])) {
            $this->options["config_file"] = 'config/config.php';
        }
        if (!isset($this->options['config_cache_path'])) {
            $this->options['config_cache_path'] = 'var/cache/config-cache.php';
        }
        if (!isset($this->options["postProcessors"])) {
            $this->options['postProcessors'] = [
                new OwnParameterPostProcessor()
            ];
        }
       
        $this->config = new Dot([]);

        $this->aggregator = $this->createAggregator($this->options);

            
        $config = $this->aggregator->getMergedConfig();
        $this->config->setReference($config);
        
        //$this->injectProvider(new ArrayProvider(["opa" => "%app.project_dir%Sopa"]));
    }

    public function createAggregator($options) {
        if (!$this->hasCache()) {
            $loaders = require $options["config_file"];
            
            // Include cache configuration
            $cache_config_provider = new ArrayProvider($options);
            array_unshift($loaders, $cache_config_provider);
            
            return new ConfigAggregator(
                $loaders,
                $options['config_cache_path'], 
                $options['postProcessors']
            );
        }
        
        // we don't really care, it is just to get the cached file
        return new ConfigAggregator(
            [],
            $options['config_cache_path'],
            $options['postProcessors']
        );   

    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        $app->registerExtension('config', $extension);
        return $extension;
    }

    public function inject(mixed $obj) {
        if (is_callable($obj)) {
            $this->injectProvider($obj);
        }
    }

    public function injectProvider($provider) {
        $chunk = $this->aggregator->getConfigFromProvider($provider);
        $values = $this->config->all();
        $chunk = $this->aggregator->postProcessConfigChunk($this->options['postProcessors'], $values, $chunk);
        $this->aggregator->mergeConfig($values, $chunk, $provider);
        $this->config->setReference($values);
    }

    public function getConfig() {
        return $this->config;
    }


    public function hasCache() {
        if (!isset($this->has_cache)) {
            $this->has_cache = file_exists($this->options['config_cache_path']);
        }
        return $this->has_cache;
    }

    public function updateCache(bool $force = false) {
        $this->aggregator->updateCache($force);
    }

    public function onReady($event) {
        // now we can save the cache file because nothing more is going to be inject
        if (!$this->hasCache()) {
            $this->updateCache();
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            "core.ready" => "onReady"
        ];
    }
}
