<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL\Support;

class InMemoryConnection
{
	public function __construct(
		private array $tables,
		private int &$lastInsertId
	) {
	}

	public function prepare(string $sql): InMemoryStatement
	{
		$table = $this->extractTable($sql);
		$rows = $this->tables[$table] ?? [];
		$isCount = str_contains($sql, 'COUNT(*)');

		// Detect filter column from WHERE clause
		$filterColumn = null;
		$isInClause = false;
		if (preg_match('/WHERE\s+`?(\w+)`?\s*=\s*\?/i', $sql, $matches)) {
			$filterColumn = $matches[1];
		} elseif (preg_match('/WHERE\s+`?(\w+)`?\s+IN\s*\(/i', $sql, $matches)) {
			$filterColumn = $matches[1];
			$isInClause = true;
		}

		return new InMemoryStatement($rows, $filterColumn, $isCount, $isInClause);
	}

	public function lastInsertId(): string
	{
		return (string) $this->lastInsertId;
	}

	private function extractTable(string $sql): string
	{
		// Match FROM `table`, INTO `table`, UPDATE `table`
		if (preg_match('/(?:FROM|INTO|UPDATE)\s+`?(\w+)`?/i', $sql, $matches)) {
			return $matches[1];
		}
		return '';
	}
}
