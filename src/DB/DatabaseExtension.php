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
use ON\DB\Container\CycleRegistryGeneratorFactory;
use ON\DB\Container\DatabaseManagerFactory;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\Cycle\Schema\CycleRegistryGenerator;
use ON\DB\Middleware\DatabaseScopeMiddleware;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Init\InitContext;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Swoole\SwooleDatabasePool;
use Psr\Container\ContainerInterface;

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
			$event->containerConfig->addFactory(CycleRegistryGenerator::class, CycleRegistryGeneratorFactory::class);
		});

		$init->on(PipelineReadyEvent::class, [$this, 'onPipelineReady']);
	}

	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$event->pipeline->pipe(DatabaseScopeMiddleware::class, priority: PHP_INT_MAX);
	}

	/** @internal */
	public static function resolvePool(ContainerInterface $container): DatabasePoolInterface
	{
		return $container->has(DatabasePoolInterface::class)
			? $container->get(DatabasePoolInterface::class)
			: new SwooleDatabasePool();
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
				$cacheDir = $this->app->paths->get('cache')->getAbsolutePath();
				CachePathCleaner::removeFile($this->app->paths->get('cache')->append('cycle.schema.php')->getAbsolutePath());
				foreach (glob(rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'cycle.schema.*.php') ?: [] as $file) {
					CachePathCleaner::removeFile($file);
				}
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
