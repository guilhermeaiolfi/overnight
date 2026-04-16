<?php

declare(strict_types=1);

namespace ON\Logging;

use ON\Application;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Logging\Command\MonitorLogsCommand;
use ON\Logging\Container\LoggerFactory;
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

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);
		$app->registerExtension('translation', $extension);

		return $extension;
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
			$containerConfig = $this->app->config->get(ContainerConfig::class);
			$containerConfig->addFactories([
				LoggerInterface::class => LoggerFactory::class,
			]);

			$this->dispatchStateChange('ready');
		});

		$this->app->ext('config')->when('setup', function () {
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
			$this->app->ext('console')->when('ready', function () {
				$this->app->console->addCommand(MonitorLogsCommand::class);
			});
		}
	}

	public function setup(): void
	{
	}
}
