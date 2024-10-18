<?php
declare (strict_types=1);

namespace ON\Logging;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use ON\Logging\LoggingConfig;

final class LoggerFactory
{
    public function __invoke(ContainerInterface $container): LoggerInterface
    {
        // create a log channel
        $logger = new Logger('name');

        $config = $container->get(LoggingConfig::class);
        $settings = $config["default"];
        if (null === $settings) {
            throw new \Exception("The default configuration for logging was not found");
        }

        $path = $settings["path"];

        if ($settings["type"] == "rotating_file") {
            $format = date($settings["date_format"] ?? "Ymd");
            $path = str_replace("%date%", $format, $path);
        }
        
        $logger->pushHandler(new StreamHandler($path, Level::Warning));
        return $logger;
    }
}
