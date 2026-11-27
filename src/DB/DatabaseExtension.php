<?php

declare(strict_types=1);

namespace ON\DB;

use Cycle\Database\Config\DriverConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Migrations\Config\MigrationConfig;
use ON\Cache\CacheClearerDefinition;
use ON\Cache\CachePathCleaner;
use ON\Cache\Init\Event\CacheClearersConfigureEvent;
use ON\Config\Init\Event\ConfigReadyEvent;
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
use ON\FS\Path;
use ON\FS\PathInterface;
use ON\Init\Attribute\Before;
use ON\Init\Attribute\On;
use ON\Init\InitContext;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Swoole\SwooleDatabaseStore;

class DatabaseExtension extends AbstractExtension
{
	public const ID = 'db';

	#[Before]
	public function onConfigReady(ConfigReadyEvent $event): void
	{
		$config = $event->config->get(DatabaseConfig::class);
		$projectPath = $this->app->paths->get('project');
		$migration = $config->get('migration');
		if ($migration instanceof MigrationConfig) {
			$options = $migration->toArray();
			$directory = $migration->getDirectory();
			$options['directory'] = Path::from($directory === '' ? '.' : $directory, $projectPath)->getAbsolutePath();
			if (isset($options['vendorDirectories'])) {
				$options['vendorDirectories'] = array_map(
					static fn (string $path): string => Path::from($path === '' ? '.' : $path, $projectPath)->getAbsolutePath(),
					$migration->getVendorDirectories()
				);
			}
			$config->set('migration', new MigrationConfig($options));
		}

		foreach ($config->get('databases', []) as $name => $database) {
			$dsn = $database['dsn'] ?? null;
			if (is_a($database['class'] ?? '', PdoDatabase::class, true) && is_string($dsn) && str_starts_with($dsn, 'sqlite:')) {
				$database['dsn'] = 'sqlite:' . $this->resolveSqliteFile(substr($dsn, strlen('sqlite:')), $projectPath);
			} elseif (is_a($database['class'] ?? '', CycleDatabase::class, true)) {
				foreach ($database['options']['connections'] ?? [] as $key => $driver) {
					$connection = $driver instanceof DriverConfig ? $driver->connection : null;
					if ($driver instanceof DriverConfig && $connection instanceof FileConnectionConfig) {
						$connection = clone $connection;
						$connection->database = $this->resolveSqliteFile($connection->database, $projectPath);
						$driver = clone $driver;
						$driver->connection = $connection;
						$database['options']['connections'][$key] = $driver;
					}
				}
			}
			$config->set("databases.{$name}", $database);
		}
	}

	private function resolveSqliteFile(string $file, PathInterface $projectPath): string
	{
		if ($file === '' || $file === ':memory:' || str_starts_with($file, 'file:') || Path::isAbsoluteString($file)) {
			return $file;
		}

		return Path::from($file, $projectPath)->getAbsolutePath();
	}

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
		$event->containerConfig->addFactory(DatabaseManager::class, DatabaseManagerFactory::class);
		$event->containerConfig->addFactory(CycleRegistryGenerator::class, CycleRegistryGeneratorFactory::class);
		$event->containerConfig->set('definitions.services.' . DatabaseStoreInterface::class, SwooleDatabaseStore::class);
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$event->pipeline->pipe(DatabaseScopeMiddleware::class, priority: PHP_INT_MAX);
	}

	#[On(optional: true)]
	public function onConsoleReady(ConsoleReadyEvent $event): void
	{
		$event->console->addCommand(MigrateCommand::class);
		$event->console->addCommand(MigrateUpCommand::class);
		$event->console->addCommand(MigrateAllCommand::class);
		$event->console->addCommand(MigrateDownCommand::class);
		$event->console->addCommand(MigrateStatusCommand::class);
	}

	public function start(InitContext $context): void
	{
	}

	#[On(optional: true)]
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

	public function onConfigConfigure(): void
	{

	}
}
