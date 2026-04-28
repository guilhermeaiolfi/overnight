<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\StatementInterface as CycleStatementInterface;

class RestDataLoader
{
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
		DatabaseInterface $database,
		?array $columns = null,
		?string $where = null,
		?string $orderBy = null,
		?int $limit = null,
		?int $offset = null
	): array {
		if (empty($parentIds)) {
			return [];
		}

		$query = $database->select()->from($table)->where($foreignKey, 'IN', $parentIds);
		if ($columns !== null) {
			$query->columns($columns);
		}

		if ($where !== null && $where !== '') {
			$query->where(new Fragment($where));
		}

		if ($orderBy !== null && $orderBy !== '') {
			$query->orderBy(new Fragment($orderBy), null);
		}

		$allRows = $query->fetchAll(CycleStatementInterface::FETCH_ASSOC);

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

		return $grouped;
	}

	public function clear(): void
	{
	}
}
