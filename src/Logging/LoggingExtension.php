<?php

declare(strict_types=1);

namespace ON\Logging;

use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Logging\Command\MonitorLogsCommand;
use ON\Logging\Container\LoggerFactory;
use Psr\Log\LoggerInterface;

class LoggingExtension extends AbstractExtension
{
	public const ID = 'logging';

	protected array $configs = [];

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			LoggerInterface::class => LoggerFactory::class,
		]);
	}

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$translationConfig = $event->config->get(LoggingConfig::class);
		$translationConfig->mergeConfig([
			"default" => [
				"type" => "rotating_file",
				"path" => "var/log/app.%date%.log",
				"date_format" => "Ymd",
			],
		]);
	}

	#[On(optional: true)]
	public function onConsoleReady(ConsoleReadyEvent $event): void
	{
		$event->console->addCommand(MonitorLogsCommand::class);
	}
}
