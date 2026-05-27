<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\SubQuery;
use Cycle\Database\Query\SelectQuery;

trait LimitedSubquerySupport
{
	protected function limitedSubquery(
		SelectQuery $inner,
		array $outerColumns,
		array $partitionColumns,
		array $orders,
		?int $limit,
		?int $offset
	): SelectQuery {
		$rowNumberAlias = '__on_row_number';
		$partitionSql = implode(', ', $partitionColumns);
		$rowNumberSql = 'ROW_NUMBER() OVER (PARTITION BY '
			. $partitionSql
			. $this->windowOrderSql($orders)
			. ') AS '
			. $rowNumberAlias;

		$inner->columns([...$inner->getColumns(), new Fragment($rowNumberSql)]);

		$alias = '__on_limited_relation';
		$outer = $this->items->getDatabase()->select($outerColumns)
			->from(new SubQuery($inner, $alias));

		$offset ??= 0;
		if ($offset > 0) {
			$outer->where(new Expression($alias . '.' . $rowNumberAlias), '>', $offset);
		}

		if ($limit !== null) {
			$outer->where(new Expression($alias . '.' . $rowNumberAlias), '<=', $offset + $limit);
		}

		return $outer;
	}

	private function windowOrderSql(array $orders): string
	{
		return $orders === [] ? '' : ' ORDER BY ' . implode(', ', $orders);
	}

}
