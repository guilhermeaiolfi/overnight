<?php

declare(strict_types=1);

namespace ON\DB;

use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\DB\Command\MigrateCommand;
use ON\DB\Command\MigrateDownCommand;
use ON\DB\Command\MigrateUpCommand;
use ON\DB\Container\CycleDatabaseFactory;
use ON\DB\Cycle\CycleDatabase;
use ON\Extension\AbstractExtension;

class DatabaseExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function boot(): void
	{
		if ($this->app->isCli()) {
			$this->app->ext('console')->when('ready', function ($console) {
				$console->addCommand(MigrateCommand::class);
				$console->addCommand(MigrateUpCommand::class);
				$console->addCommand(MigrateDownCommand::class);
			});
		}

		$this->app->ext('config')->when('setup', function (ConfigExtension $configExt) {
			$containerConfig = $configExt->get(ContainerConfig::class);
			$containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
		});
	}

	public function setup(): void
	{
		$this->setState('ready');
	}

	public function onContainerConfig(): void
	{

	}

	public function onConfigSetup(): void
	{

	}
}
