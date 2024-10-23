<?php
 
namespace ON\Cache;

use Exception;
use ON\Application;
use ON\Config\ContainerConfig;
use ON\Extension\AbstractExtension;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheExtension extends AbstractExtension
{
    protected array $pendingTasks = [ "container:define" ];

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        return $extension;
    }

    public function __construct (
        protected Application $app, 
        protected array $options
    )
    {
    }

    public function setup($counter): bool {
        if ($this->removePendingTask("container:define")) {
            $config = $this->app->ext('config');

            if (!isset($config)) {
                throw new Exception("Cache Extension needs the config extension");
            }
            $containerConfig = $config->get(ContainerConfig::class);
            $containerConfig->mergeConfigArray([
                "definitions" => [
                    "aliases" => [
                        //CacheInterface::class                      => \Symfony\Contracts\Cache\CacheInterface::class
                    ],
                    "factories" => [
                        CacheInterface::class                      => \ON\Cache\Container\CacheFactory::class,
                        FilesystemAdapter::class                   => \ON\Cache\Container\FilesystemAdapterFactory::class
                    ]
                ]
            ]);
        }

        if (empty($this->getPendingTasks())) {
            return true;
        }

        return false;
    }
}