<?php

namespace ON\Extension;

use Exception;
use ON\Application;

use Laminas\Stdlib\Glob;

// To enable or disable caching, set the `ConfigBuilder::ENABLE_CACHE` boolean in
// `config/autoload/framework.global.php`.
class ObjectConfigExtension extends AbstractExtension
{
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
        if (!isset($this->options['debug'])) {
            $this->options['debug'] = $_ENV["APP_ENV"] != "production";
        }
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        $app->registerExtension('config', $extension);
        return $extension;
    }

    public function has(string $className): bool 
    {
        return isset($this->configs[$className]);
    }

    public function get(string $className = null): mixed {
        if ($className === null) {
            return $this->configs;
        }

        if (!isset($this->configs[$className])) {
            $this->configs[$className] = new $className();
        }
        return $this->configs[$className];
    }

    public function set(string $className, mixed $obj) {
        $this->configs[$className] = $obj;
    }

    public function setup(int $counter): bool
    {
        // we need to give a chance to extensions to register configs before application code
        if ($counter == 1) {
            $files = Glob::glob("configuration/" . sprintf('{,/*.}{all,%s,local}.php', $_ENV['APP_ENV'] ?? 'production'), Glob::GLOB_BRACE, true);
            foreach ($files as $file) {
                $obj = include_once($file);
                //dd(get_class($obj));
                if (!is_object($obj)) {
                    throw new Exception(
                        sprintf("Configuration file (%s) should return an object, return: %s.", $file, $obj)
                    );
                } else {
                    $class_name = get_class($obj);
                    if (isset($this->configs[$class_name])) {
                        $this->configs[$class_name]->mergeRecursiveDistinct($obj);
                    } else {
                        $this->configs[$class_name] = $obj;
                    }
                }
            }
            //dd($this->configs);
            return true;
        }
        return false;
    }

    public function ready() {
       
    }
}
