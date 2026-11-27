<?php

declare(strict_types=1);

namespace ON\Logging;

use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Logging\Command\MonitorLogsCommand;
use ON\Logging\Container\LoggerFactory;
use Psr\Log\LoggerInterface;

class LoggingExtension extends AbstractExtension
{
	public const ID = 'logging';

	protected array $configs = [];

	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			$event->containerConfig->addFactories([
				LoggerInterface::class => LoggerFactory::class,
			]);
		});
		$init->on(ConfigConfigureEvent::class, function (ConfigConfigureEvent $event): void {
			$translationConfig = $event->config->get(LoggingConfig::class);
			$translationConfig->mergeConfig([
				"default" => [
					"type" => "rotating_file",
					"path" => "var/log/app.%date%.log",
					"date_format" => "Ymd",
				],
			]);
		});

		if ($this->app->hasExtension('console')) {
			$init->on(ConsoleReadyEvent::class, function (): void {
				$this->app->console->addCommand(MonitorLogsCommand::class);
			});
		}
	}
}
