<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL\Support;

class InMemoryStatement
{
	public function __construct(
		private array $rows,
		private ?string $filterColumn = null,
		private bool $isCount = false,
		private bool $isInClause = false
	) {
	}

	public function execute(array $params = []): bool
	{
		if ($this->isCount) {
			// For count queries, apply the same filtering
			if ($this->filterColumn !== null && !empty($params)) {
				$col = $this->filterColumn;
				$this->rows = array_values(array_filter(
					$this->rows,
					fn($row) => in_array($row[$col] ?? null, $params)
				));
			}
			// Store count result
			$this->rows = [['cnt' => count($this->rows)]];
			return true;
		}

		if ($this->filterColumn !== null && !empty($params)) {
			$col = $this->filterColumn;
			if ($this->isInClause) {
				$this->rows = array_values(array_filter(
					$this->rows,
					fn($row) => in_array($row[$col] ?? null, $params)
				));
			} else {
				$this->rows = array_values(array_filter(
					$this->rows,
					fn($row) => ($row[$col] ?? null) == $params[0]
				));
			}
		}

		return true;
	}

	public function fetchAll(int $mode = 2): array
	{
		return array_map(fn($r) => (object) $r, $this->rows);
	}

	public function fetch(int $mode = 2): ?object
	{
		$row = array_shift($this->rows);
		return $row ? (object) $row : null;
	}

	public function rowCount(): int
	{
		return count($this->rows);
	}
}
