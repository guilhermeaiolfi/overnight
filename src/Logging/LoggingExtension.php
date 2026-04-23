<?php

declare(strict_types=1);

namespace ON\Logging;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Config\Init\ConfigInitEvents;
use ON\Console\Init\ConsoleInitEvents;
use ON\Container\Init\ContainerInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Logging\Command\MonitorLogsCommand;
use ON\Logging\Container\LoggerFactory;
use Psr\Log\LoggerInterface;

class LoggingExtension extends AbstractExtension
{
	public const ID = 'logging';

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
	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::SETUP, function (): void {
			$containerConfig = $this->app->config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				LoggerInterface::class => LoggerFactory::class,
			]);

		});

		$init->on(ConfigInitEvents::SETUP, function (): void {
			$translationConfig = $this->app->config->get(LoggingConfig::class);
			$translationConfig->mergeConfig([
				"default" => [
					"type" => "rotating_file",
					"path" => "var/log/app.%date%.log",
					"date_format" => "Ymd",
				],
			]);
		});

		if ($this->app->hasExtension('console')) {
			$init->on(ConsoleInitEvents::READY, function (): void {
				$this->app->console->addCommand(MonitorLogsCommand::class);
			});
		}
	}

}
