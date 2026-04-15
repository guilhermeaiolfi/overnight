<?php

declare(strict_types=1);

namespace ON\RestApi;

class RestDataLoader
{
	protected array $cache = [];

	/**
	 * Batch-load related records for a set of parent IDs.
	 * Returns array keyed by parent ID value.
	 *
	 * When limit/offset are provided, they apply per parent — the loader
	 * fetches all matching rows in a single IN query, groups by parent ID,
	 * then slices each group in PHP.
	 */
	public function loadBatch(
		string $table,
		string $foreignKey,
		array $parentIds,
		\PDO $connection,
		?array $columns = null,
		?string $where = null,
		?string $orderBy = null,
		?int $limit = null,
		?int $offset = null
	): array {
		if (empty($parentIds)) {
			return [];
		}

		$cacheKey = $table . ':' . $foreignKey . ':' . implode(',', $parentIds);
		if (isset($this->cache[$cacheKey])) {
			return $this->cache[$cacheKey];
		}

		$sanitizedTable = $this->quoteIdentifier($table);
		$sanitizedFK = $this->quoteIdentifier($foreignKey);

		$columnList = $columns !== null
			? implode(', ', array_map([$this, 'quoteIdentifier'], $columns))
			: '*';

		$placeholders = implode(', ', array_fill(0, count($parentIds), '?'));
		$sql = "SELECT {$columnList} FROM {$sanitizedTable} WHERE {$sanitizedFK} IN ({$placeholders})";
		$values = $parentIds;

		if ($where !== null && $where !== '') {
			$sql .= " AND ({$where})";
		}

		if ($orderBy !== null && $orderBy !== '') {
			$sql .= " ORDER BY {$orderBy}";
		}

		$stmt = $connection->prepare($sql);
		$stmt->execute($values);
		$allRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

		// Group by parent ID
		$grouped = [];
		foreach ($parentIds as $id) {
			$grouped[$id] = [];
		}
		foreach ($allRows as $row) {
			$key = $row[$foreignKey] ?? null;
			if ($key !== null && isset($grouped[$key])) {
				$grouped[$key][] = $row;
			}
		}

		// Apply per-parent limit/offset
		if ($limit !== null || $offset !== null) {
			$off = $offset ?? 0;
			foreach ($grouped as $id => $rows) {
				$grouped[$id] = array_slice($rows, $off, $limit);
			}
		}

		$this->cache[$cacheKey] = $grouped;

		return $grouped;
	}

	public function clear(): void
	{
		$this->cache = [];
	}

	protected function quoteIdentifier(string $identifier): string
	{
		$sanitized = preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
		return "`{$sanitized}`";
	}
}
