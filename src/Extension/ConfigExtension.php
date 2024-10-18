<?php

namespace ON\Extension;

use Exception;
use ON\Application;

use ON\Config\ConfigBuilder;
use Adbar\Dot;
use ON\Config\ConfigInterface;
use ON\Config\OwnParameterPostProcessor;
use ON\Config\Provider\ArrayProvider;
use ON\Event\EventSubscriberInterface;

// To enable or disable caching, set the `ConfigBuilder::ENABLE_CACHE` boolean in
// `config/autoload/framework.global.php`.
class ConfigExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    protected Dot $config;
    protected Application $app;
    protected array $options;
    protected ?bool $has_cache = null;
    protected ConfigBuilder $builder;
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
        if (!isset($this->options['debug'])) {
            $this->options['debug'] = $_ENV["APP_ENV"] != "production";
        }
        
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        $app->registerExtension('config', $extension);
        return $extension;
    }

    public function setup(int $counter): bool
    {
        if ($counter == 0) {
            $this->config = new Dot([]);

            $this->builder = $this->createBuilder($this->options);

            $this->config->setReference($this->builder->getMergedConfig());
        }

        // only set as ready after all extensions inject its content here
        if (empty($this->app->getExtensionsByPendingTask('config:inject'))) {
            return true;
        }
        return false;
    }

    public function ready() {
        // now we can save the cache file because nothing more is going to be injected
        if (!$_ENV["APP_DEBUG"]) {
            $this->persist();
        }
    }

    public function createBuilder($options) {

        $loaders = require $options["config_file"];
        
        return new ConfigBuilder(
            $loaders,
            $options['config_cache_path'], 
            $options['postProcessors'],
            [],
            $options["debug"]
        );
    }

    public function load(mixed $obj) {
        if (!$this->builder->shouldLoad()) {
            return;
        }
        if (is_callable($obj)) {
            $values = $this->builder->loadConfigFromProvider($obj);
            $this->config->setReference($values);
        }
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

    public function persist(bool $force = false) {
        $this->builder->persist($force);
    }
}
