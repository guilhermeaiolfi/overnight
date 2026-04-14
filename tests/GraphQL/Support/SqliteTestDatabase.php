<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL\Support;

use ON\DB\DatabaseInterface;
use PDO;

class SqliteTestDatabase implements DatabaseInterface
{
	private PDO $pdo;

	/**
	 * @param array<string, array{columns: array<string, string>, rows: list<array<string, mixed>>}> $tables
	 */
	public function __construct(array $tables = [])
	{
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		foreach ($tables as $name => $definition) {
			$this->createTable($name, $definition['columns'], $definition['rows']);
		}
	}

	private function createTable(string $name, array $columns, array $rows): void
	{
		$columnDefs = [];
		foreach ($columns as $colName => $colType) {
			$columnDefs[] = "`{$colName}` {$colType}";
		}

		$sql = "CREATE TABLE `{$name}` (" . implode(', ', $columnDefs) . ")";
		$this->pdo->exec($sql);

		if (empty($rows)) {
			return;
		}

		$colNames = array_keys($columns);
		$placeholders = implode(', ', array_fill(0, count($colNames), '?'));
		$quotedCols = implode(', ', array_map(fn(string $c) => "`{$c}`", $colNames));

		$stmt = $this->pdo->prepare("INSERT INTO `{$name}` ({$quotedCols}) VALUES ({$placeholders})");

		foreach ($rows as $row) {
			$values = [];
			foreach ($colNames as $col) {
				$values[] = $row[$col] ?? null;
			}
			$stmt->execute($values);
		}
	}

	public function getConnection(): mixed
	{
		return $this->pdo;
	}

	public function getResource(): mixed
	{
		return $this->pdo;
	}

	public function setName(string $name): void
	{
	}

	public function getName(): string
	{
		return 'sqlite-test';
	}
}
