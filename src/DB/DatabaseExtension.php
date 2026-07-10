<?php

declare(strict_types=1);

namespace ON\DB;

use ON\Application;
use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Console\Init\Event\ConsoleReadyEvent;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\DB\Command\MigrateAllCommand;
use ON\DB\Command\MigrateCommand;
use ON\DB\Command\MigrateDownCommand;
use ON\DB\Command\MigrateStatusCommand;
use ON\DB\Command\MigrateUpCommand;
use ON\DB\Container\CycleDatabaseFactory;
use ON\DB\Container\DatabaseManagerFactory;
use ON\DB\Cycle\CycleDatabase;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Init\InitContext;

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
			$init->on(ConsoleReadyEvent::class, function (): void {
				$this->app->console->addCommand(MigrateCommand::class);
				$this->app->console->addCommand(MigrateUpCommand::class);
				$this->app->console->addCommand(MigrateAllCommand::class);
				$this->app->console->addCommand(MigrateDownCommand::class);
				$this->app->console->addCommand(MigrateStatusCommand::class);
			});

			if (class_exists(CacheClearersConfigureEvent::class)) {
				$init->on(CacheClearersConfigureEvent::class, [$this, 'onCacheClearersConfigure']);
			}
		}

		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			$event->containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
			$event->containerConfig->addFactory(DatabaseManager::class, DatabaseManagerFactory::class);
		});
	}

	public function start(InitContext $context): void
	{
	}

	public function onCacheClearersConfigure(CacheClearersConfigureEvent $event): void
	{
		$event->registry->add(new CacheClearerDefinition(
			name: 'orm-schema',
			label: 'ORM schema',
			clear: function (): void {
				CachePathCleaner::removeFile($this->app->paths->get('cache')->append('cycle.schema.php')->getAbsolutePath());
			},
			priority: 75,
			description: 'Clears cached Cycle ORM schema.'
		));
	}

	public function onContainerConfigure(): void
	{

	}

	public function onConfigConfigure(): void
	{

	}
}
