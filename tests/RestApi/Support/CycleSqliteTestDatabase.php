<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;

class CycleSqliteTestDatabase
{
	private DatabaseInterface $database;

	/**
	 * @param array<string, array{columns: array<string, string>, rows: list<array<string, mixed>>}> $tables
	 */
	public function __construct(array $tables = [])
	{
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig()
				),
			],
		]));

		$this->database = $manager->database('default');

		foreach ($tables as $name => $definition) {
			$this->createTable($name, $definition['columns'], $definition['rows']);
		}
	}

	public function database(): DatabaseInterface
	{
		return $this->database;
	}

	private function createTable(string $name, array $columns, array $rows): void
	{
		$columnDefs = [];
		foreach ($columns as $colName => $colType) {
			$columnDefs[] = "`{$colName}` {$colType}";
		}

		$sql = "CREATE TABLE `{$name}` (" . implode(', ', $columnDefs) . ")";
		$this->database->execute($sql);

		if ($rows === []) {
			return;
		}

		$colNames = array_keys($columns);
		$placeholders = implode(', ', array_fill(0, count($colNames), '?'));
		$quotedCols = implode(', ', array_map(fn (string $c) => "`{$c}`", $colNames));
		$sql = "INSERT INTO `{$name}` ({$quotedCols}) VALUES ({$placeholders})";

		foreach ($rows as $row) {
			$values = [];
			foreach ($colNames as $col) {
				$values[] = $row[$col] ?? null;
			}
			$this->database->execute($sql, $values);
		}
	}
}
