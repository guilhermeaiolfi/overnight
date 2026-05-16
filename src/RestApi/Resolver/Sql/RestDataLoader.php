<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\FragmentInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
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
		?CollectionInterface $filterCollection = null,
		array $filters = [],
		?SqlFilterApplier $filterApplier = null,
		array $orderBy = [],
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

		if ($filterCollection !== null && $filters !== [] && $filterApplier !== null) {
			$filterApplier->apply($query, $filterCollection, $filters);
		}

		foreach ($orderBy as $order) {
			if (
				!is_array($order)
				|| !isset($order['expression'])
				|| !$order['expression'] instanceof FragmentInterface
			) {
				continue;
			}

			$query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
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
