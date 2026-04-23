<?php

declare(strict_types=1);

namespace ON\DB;

use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\Config\Init\ConfigInitEvents;
use ON\Console\Init\ConsoleInitEvents;
use ON\DB\Command\MigrateCommand;
use ON\DB\Command\MigrateDownCommand;
use ON\DB\Command\MigrateUpCommand;
use ON\DB\Container\CycleDatabaseFactory;
use ON\DB\Container\DatabaseManagerFactory;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

class DatabaseExtension extends AbstractExtension
{
	public const ID = 'db';
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		if ($this->app->isCli()) {
			$init->on(ConsoleInitEvents::READY, function (): void {
				$this->app->console->addCommand(MigrateCommand::class);
				$this->app->console->addCommand(MigrateUpCommand::class);
				$this->app->console->addCommand(MigrateDownCommand::class);
			});
		}

		$init->on(ConfigInitEvents::SETUP, function (object $event): void {
			$containerConfig = $event->config->get(ContainerConfig::class);
			$containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
			$containerConfig->addFactory(DatabaseManager::class, DatabaseManagerFactory::class);
		});
	}

	public function start(\ON\Init\InitContext $context): void
	{
	}

	public function onContainerConfig(): void
	{

	}

	public function onConfigSetup(): void
	{

	}
}
