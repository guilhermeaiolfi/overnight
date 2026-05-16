<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\SubQuery;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use ON\ORM\Definition\Collection\CollectionInterface;

class RestDataLoader
{
	/**
	 * Batch-load related records for a set of parent IDs.
	 * Returns array keyed by parent ID value.
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

		if ($limit !== null || $offset !== null) {
			return $this->loadBatchLimited(
				$table,
				$foreignKey,
				$parentIds,
				$database,
				$columns,
				$filterCollection,
				$filters,
				$filterApplier,
				$orderBy,
				$limit,
				$offset
			);
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

		return $grouped;
	}

	protected function loadBatchLimited(
		string $table,
		string $foreignKey,
		array $parentIds,
		DatabaseInterface $database,
		?array $columns,
		?CollectionInterface $filterCollection,
		array $filters,
		?SqlFilterApplier $filterApplier,
		array $orderBy,
		?int $limit,
		?int $offset
	): array {
		$columns = $this->columnsForLimitedQuery($columns, $filterCollection, $foreignKey);
		$rowNumberAlias = '__on_row_number';
		$partitionColumn = $this->compile($database, new Expression($table . '.' . $foreignKey));
		$orderSql = $this->windowOrderSql($database, $orderBy);
		$rowNumberSql = 'ROW_NUMBER() OVER (PARTITION BY ' . $partitionColumn . $orderSql . ') AS ' . $this->identifier($database, $rowNumberAlias);

		$innerColumns = $columns;
		$innerColumns[] = new Fragment($rowNumberSql);

		$inner = $database->select()
			->from($table)
			->columns($innerColumns)
			->where($foreignKey, 'IN', $parentIds);

		if ($filterCollection !== null && $filters !== [] && $filterApplier !== null) {
			$filterApplier->apply($inner, $filterCollection, $filters);
		}

		$alias = '__on_limited_relation';
		$outer = $database->select($columns)
			->from(new SubQuery($inner, $alias));

		$offset ??= 0;
		if ($offset > 0) {
			$outer->where(new Expression($alias . '.' . $rowNumberAlias), '>', $offset);
		}

		if ($limit !== null) {
			$outer->where(new Expression($alias . '.' . $rowNumberAlias), '<=', $offset + $limit);
		}

		return $this->groupRows(
			$parentIds,
			$foreignKey,
			$outer->fetchAll(CycleStatementInterface::FETCH_ASSOC)
		);
	}

	protected function groupRows(array $parentIds, string $foreignKey, array $rows): array
	{
		$grouped = [];
		foreach ($parentIds as $id) {
			$grouped[$id] = [];
		}

		foreach ($rows as $row) {
			$key = $row[$foreignKey] ?? null;
			if ($key !== null && isset($grouped[$key])) {
				$grouped[$key][] = $row;
			}
		}

		return $grouped;
	}

	protected function columnsForLimitedQuery(?array $columns, ?CollectionInterface $collection, string $foreignKey): array
	{
		if ($columns === null) {
			$columns = [];
			if ($collection !== null) {
				foreach ($collection->fields as $field) {
					$columns[] = $field->getColumn();
				}
			}
		}

		if (!in_array($foreignKey, $columns, true)) {
			$columns[] = $foreignKey;
		}

		return array_values(array_unique($columns));
	}

	protected function windowOrderSql(DatabaseInterface $database, array $orderBy): string
	{
		$parts = [];
		foreach ($orderBy as $order) {
			if (
				!is_array($order)
				|| !isset($order['expression'])
				|| !$order['expression'] instanceof FragmentInterface
			) {
				continue;
			}

			$direction = strtoupper((string) ($order['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
			$parts[] = $this->compile($database, $order['expression']) . ' ' . $direction;
		}

		return $parts === [] ? '' : ' ORDER BY ' . implode(', ', $parts);
	}

	protected function compile(DatabaseInterface $database, FragmentInterface $expression): string
	{
		return $database->getDriver()->getQueryCompiler()->compile(
			new QueryParameters(),
			$database->getPrefix(),
			$expression
		);
	}

	protected function identifier(DatabaseInterface $database, string $identifier): string
	{
		return $database->getDriver()->getQueryCompiler()->quoteIdentifier($identifier);
	}

	public function clear(): void
	{
	}
}
