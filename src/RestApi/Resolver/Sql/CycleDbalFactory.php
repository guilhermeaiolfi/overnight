<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\Config\MySQL\DsnConnectionConfig as MySQLDsnConnectionConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\DsnConnectionConfig as PostgresDsnConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\DsnConnectionConfig as SQLiteDsnConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\Config\SQLServer\DsnConnectionConfig as SQLServerDsnConnectionConfig;
use Cycle\Database\Config\SQLServerDriverConfig;
use Cycle\Database\Database;
use Cycle\Database\Driver\MySQL\MySQLDriver;
use Cycle\Database\Driver\Postgres\PostgresDriver;
use Cycle\Database\Driver\SQLite\SQLiteDriver;
use Cycle\Database\Driver\SQLServer\SQLServerDriver;
use PDO;

final class CycleDbalFactory
{
	public function fromPdo(PDO $pdo, string $name = 'rest-sql'): Database
	{
		$driverName = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

		return match ($driverName) {
			'sqlite' => new Database(
				$name,
				'',
				ConnectedSQLiteDriver::fromPdo(
					new SQLiteDriverConfig(new SQLiteDsnConnectionConfig('sqlite::memory:')),
					$pdo
				),
			),
			'mysql' => new Database(
				$name,
				'',
				ConnectedMySQLDriver::fromPdo(
					new MySQLDriverConfig(new MySQLDsnConnectionConfig('mysql:host=localhost;dbname=overnight')),
					$pdo
				),
			),
			'pgsql' => new Database(
				$name,
				'',
				ConnectedPostgresDriver::fromPdo(
					new PostgresDriverConfig(new PostgresDsnConnectionConfig('pgsql:host=localhost;dbname=postgres')),
					$pdo
				),
			),
			'sqlsrv' => new Database(
				$name,
				'',
				ConnectedSqlServerDriver::fromPdo(
					new SQLServerDriverConfig(new SQLServerDsnConnectionConfig('sqlsrv:Server=localhost;Database=tempdb')),
					$pdo
				),
			),
			default => throw new \RuntimeException("Unsupported PDO driver for SQL REST resolver: {$driverName}"),
		};
	}
}

final class ConnectedSQLiteDriver extends SQLiteDriver
{
	public static function fromPdo(SQLiteDriverConfig $config, PDO $pdo): self
	{
		$driver = parent::create($config);
		$driver->pdo = $pdo;

		return $driver;
	}
}

final class ConnectedMySQLDriver extends MySQLDriver
{
	public static function fromPdo(MySQLDriverConfig $config, PDO $pdo): self
	{
		$driver = parent::create($config);
		$driver->pdo = $pdo;

		return $driver;
	}
}

final class ConnectedPostgresDriver extends PostgresDriver
{
	public static function fromPdo(PostgresDriverConfig $config, PDO $pdo): self
	{
		$driver = parent::create($config);
		$driver->pdo = $pdo;

		return $driver;
	}
}

final class ConnectedSqlServerDriver extends SQLServerDriver
{
	public static function fromPdo(SQLServerDriverConfig $config, PDO $pdo): self
	{
		$driver = parent::create($config);
		$driver->pdo = $pdo;

		return $driver;
	}
}
