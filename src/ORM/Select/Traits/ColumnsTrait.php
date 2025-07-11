<?php

declare(strict_types=1);

namespace ON\ORM\Select\Traits;

use function array_keys;
use function count;
use Cycle\Database\Query\SelectQuery;
use function explode;
use function implode;
use function is_int;
use JetBrains\PhpStorm\Pure;
use ON\ORM\Definition\Collection\Collection;

/**
 * Provides ability to add aliased columns into SelectQuery.
 *
 * @internal
 */
trait ColumnsTrait
{
	/**
	 * List of columns associated with the loader.
	 *
	 * @var array<non-empty-string, non-empty-string>
	 */
	protected array $columns = [];

	public function getColumns(): array
	{
		return $this->columns;
	}

	public function setColumns(array $columns): void
	{
		$this->columns = $columns;
	}

	/**
	 * Return column name associated with given field.
	 */
	public function fieldAlias(string $field): ?string
	{
		// The field can be a JSON path separated by ->
		$p = explode('->', $field, 2);

		$p[0] = $this->columns[$p[0]] ?? null;

		return $p[0] === null ? null : implode('->', $p);
	}

	/**
	 * Set columns into SelectQuery.
	 *
	 * @param bool        $minify    Minify column names (will work in case when query parsed in
	 *                               FETCH_NUM mode).
	 * @param string      $prefix    Prefix to be added for each column name.
	 * @param bool        $overwrite When set to true existed columns will be removed.
	 */
	protected function mountColumns(
		SelectQuery $query,
		bool $minify = false,
		string $prefix = '',
		bool $overwrite = false
	): SelectQuery {
		$alias = $this->getAlias();
		$columns = $overwrite ? [] : $query->getColumns();

		foreach ($this->columns as $internal => $external) {
			$name = $internal;
			if ($minify) {
				//Let's use column number instead of full name
				$name = 'c' . count($columns);
			}

			$columns[] = "{$alias}.{$external} AS {$prefix}{$name}";
		}

		return $query->columns($columns);
	}

	/**
	 * Return original column names.
	 */
	protected function columnNames(): array
	{
		return array_keys($this->columns);
	}

	/**
	 * Table alias of the loader.
	 */
	abstract protected function getAlias(): string;

	/**
	 * @param non-empty-string[] $columns
	 *
	 * @return array<non-empty-string, non-empty-string>
	 *
	 * @psalm-pure
	 */
	#[Pure]
	private function normalizeColumns(array $columns): array
	{
		$result = [];
		foreach ($columns as $alias => $column) {
			$result[is_int($alias) ? $column : $alias] = $column;
		}

		return $result;
	}

	public function getMandatoryColumns(Collection $collection): array
	{
		$columns = [];
		foreach ($collection->fields as $field) {
			if ($field->isPrimaryKey() || $field->getGeneratedFromRelation()) {
				$columns[$field->getName()] = $field->getColumn();
			}
		}

		return $columns;
	}

	protected function isStarFilter(array $columnsFilter): bool
	{
		return count($columnsFilter) == 1 && isset($columnsFilter[0]) && $columnsFilter[0] == "*";
	}

	/**
	 * if $columnsFilter is null, it should return all columns
	 */
	public function resolveColumns(Collection $collection, ?array $columnsFilter): array
	{
		$columns = [];

		$mandatory = $this->getMandatoryColumns($collection);

		if (! isset($columnsFilter)) {
			$columnsFilter = ["*"];
		}

		// explicity asked for no columns
		if (count($columnsFilter) == 0) {
			return [];
		}


		if ($this->isStarFilter($columnsFilter)) {
			$columns = (array) $collection->fields->getColumnNames();

			return $this->normalizeColumns($columns);
		}

		if (! $this->hasIncludeColumn($columnsFilter)) {
			$columns = (array) $collection->fields->getColumnNames();
			$columns = $this->normalizeColumns($columns);
		}

		foreach ($columnsFilter as $name => $include) {
			if ($include) {
				$columns[$name] = $name;
			} else {
				unset($columns[$name]);
			}
		}

		return array_merge($columns, $mandatory);
	}

	protected function hasIncludeColumn(array $columnsFilter): bool
	{
		foreach ($columnsFilter as $name => $include) {
			if ($include) {
				return true;
			}
		}

		return false;
	}
}
