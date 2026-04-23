<?php

declare(strict_types=1);

namespace ON\ORM\Select\Traits;

use Closure;
use Cycle\Database\Query\SelectQuery;
use ON\ORM\Select\QueryBuilder;

/**
 * Provides the ability to configure relation specific where conditions.
 *
 * @internal
 */
trait WhereTrait
{
	/**
	 * @param string        $target Query target section (accepts: where, having, onWhere, on)
	 * @param array|Closure $where  Where conditions in a form or short array form.
	 */
	private function setWhere(
		SelectQuery $query,
		string $target,
		array|Closure $where = null
	): SelectQuery {
		if ($where === []) {
			// no conditions, nothing to do
			return $query;
		}

		$proxy = new QueryBuilder($query, $this);
		$proxy = $proxy->withForward($target);

		return $proxy->where($where)->getQuery();
	}
}
