<?php

namespace ON\Logging;

use Exception;
use ON\Application;
use ON\Config\ContainerConfig;
use ON\Extension\AbstractExtension;
use Psr\Log\LoggerInterface;

class LoggingExtension extends AbstractExtension
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
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        $app->registerExtension('translation', $extension);
        return $extension;
    }

    public function setup(int $counter): bool
    {
        $config = $this->app->ext('config');

        if (!isset($config)) {
            return false;
        }
        
        $containerConfig = $config->get(ContainerConfig::class);
        $containerConfig->mergeRecursiveDistinct("definitions.factories", [
            LoggerInterface::class                              => \ON\Logging\LoggerFactory::class,
        ]);

        $translationConfig = $config->get(LoggingConfig::class);
        $translationConfig->mergeRecursiveDistinct([
            "default" => [
                "type" => "rotating_file",
                "path" => "var/log/app.%date%.log",
                "date_format" => "Ymd"
            ],
        ]);
        return true;
    }

    public function ready() {
       
    }
}
