<?php

declare(strict_types=1);

namespace ON\DataIntegration\Container;

use Closure;
use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\Driver\Driver;
use ON\Application;
use ON\Data\Database\Pdo\PdoConnection;
use ON\Data\Database\SqlRuntimeFactory;
use ON\Data\DataRuntime;
use ON\Data\Mapper\ConversionGateway;
use ON\DataIntegration\DataExtension;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use PDO;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Builds the shared ON\Data SQL query runtime from Overnight's configured
 * Cycle database connection and ConversionGateway.
 */
final class DataRuntimeFactory
{
	public function __invoke(ContainerInterface $container): DataRuntime
	{
		if (! $container->has(DatabaseManager::class)) {
			throw new RuntimeException('DataRuntime requires DatabaseManager.');
		}

		$app = $container->get(Application::class);
		$data = $app->hasExtension('data') ? $app->ext('data') : null;
		$databaseName = $data instanceof DataExtension
			? $data->getDatabaseName()
			: 'cycle';
		$cycleDatabaseName = $data instanceof DataExtension
			? $data->getCycleDatabaseName()
			: 'default';

		$cycle = $container->get(DatabaseManager::class)->getDatabase($databaseName);
		if (! $cycle instanceof CycleDatabase) {
			throw new RuntimeException(sprintf(
				'DataRuntime requires a CycleDatabase instance for "%s".',
				$databaseName
			));
		}

		$database = $cycle->getConnection()->database($cycleDatabaseName);
		$gateway = $container->get(ConversionGateway::class);

		return (new SqlRuntimeFactory())->create(
			self::connectionFromCycleDatabase($database),
			$gateway,
		);
	}

	/**
	 * Adapts a Cycle DBAL database into an ON\Data PdoConnection that reuses
	 * Cycle's underlying PDO handle and preserves its table prefix.
	 */
	public static function connectionFromCycleDatabase(CycleDatabaseInterface $database): PdoConnection
	{
		$driver = $database->getDriver(CycleDatabaseInterface::WRITE);
		if (! $driver instanceof Driver) {
			throw new RuntimeException(sprintf(
				'DataRuntime requires a Cycle PDO driver, got "%s".',
				$driver::class,
			));
		}

		$pdo = Closure::bind(
			static fn (Driver $cycleDriver): mixed => $cycleDriver->getPDO(),
			null,
			Driver::class,
		)($driver);

		if (! $pdo instanceof PDO) {
			throw new RuntimeException(sprintf(
				'DataRuntime requires a native PDO connection from Cycle driver "%s", got "%s".',
				$driver::class,
				get_debug_type($pdo),
			));
		}

		return new PdoConnection($pdo, $database->getPrefix());
	}
}
