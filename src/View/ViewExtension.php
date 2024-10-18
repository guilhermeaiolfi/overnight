<?php

namespace ON\View;

use Exception;
use League\Plates\Engine;
use ON\Application;
use ON\Config\ContainerConfig;

use ON\Extension\AbstractExtension;

class ViewExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;

    protected array $pendingTasks = [ "container:define" ];

    public function __construct(
        protected Application $app
    ) {
    }

    public static function install(Application $app, ?array $options = []): mixed {
        // we can't use the container since it may not be started yet
        $class = self::class;
        $extension = new $class($app);
        $app->registerExtension('view', $extension); // register shortcut
        return $extension;
    }
    
    public function setup(int $counter): bool
    {

        if ($this->hasPendingTask("container:define")) {
            $config = $this->app->ext('config');

            if (!isset($config)) {
                throw new Exception("Router Extension needs the config extension");
            }
            $containerConfig = $config->get(ContainerConfig::class);
            $containerConfig->mergeRecursiveDistinct([
                "definitions" => [
                    "factories" => [
                        Engine::class                                     => \ON\View\Plates\PlatesEngineFactory::class,
                    ],
                    "aliases" => [
                    ]
                ]
            ]);
            $this->removePendingTask('container:define');
            return true;
        }

        return false;
    }

    function ready() {
      
    }
}
