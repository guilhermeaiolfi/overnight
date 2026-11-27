<?php

declare (strict_types=1);

namespace ON\Logging\Container;

use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use ON\Container\ApplicationBinding;
use ON\FS\Path;
use ON\Logging\LoggingConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
	public function __invoke(ContainerInterface $container): LoggerInterface
	{
		// create a log channel
		$logger = new Logger('name');

		$config = $container->get(LoggingConfig::class);
		$settings = $config["default"];
		if (null === $settings) {
			throw new Exception("The default configuration for logging was not found");
		}

		$path = $settings["path"];

		if ($settings["type"] == "rotating_file") {
			$format = date($settings["date_format"] ?? "Ymd");
			$path = str_replace("%date%", $format, $path);
		}
		if (! str_contains($path, '://') && ($app = ApplicationBinding::get($container)) !== null) {
			$path = Path::from($path, $app->paths->get('project'))->getAbsolutePath();
		}

		$logger->pushHandler(new StreamHandler($path, Level::Warning));

		return $logger;
	}
}
