<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\QueryParameters;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\M2MRelation;

class SqlFilterApplier
{
	public function __construct(
		protected DatabaseInterface $database,
		protected SqlExpressionBuilder $expressions
	) {
	}

	public function apply(object $query, CollectionInterface $collection, array $filters, ?string $tableAlias = null): void
	{
		$filters = $this->normalizeFilters($filters);
		if ($filters === []) {
			return;
		}

		$this->applyGroup($query, $collection, $filters, $tableAlias ?? $collection->getTable());
	}

	protected function applyGroup(object $query, CollectionInterface $collection, array $filters, string $tableAlias): void
	{
		foreach ($filters as $key => $value) {
			if ($key === '_and' && is_array($value)) {
				$query->where(function ($nested) use ($collection, $value, $tableAlias) {
					foreach ($value as $group) {
						if (is_array($group)) {
							$nested->where(fn($inner) => $this->applyGroup($inner, $collection, $this->normalizeFilters($group), $tableAlias));
						}
					}
				});
				continue;
			}

			if ($key === '_or' && is_array($value)) {
				$query->where(function ($nested) use ($collection, $value, $tableAlias) {
					foreach ($value as $index => $group) {
						if (!is_array($group)) {
							continue;
						}

						$method = $index === 0 ? 'where' : 'orWhere';
						$nested->{$method}(fn($inner) => $this->applyGroup($inner, $collection, $this->normalizeFilters($group), $tableAlias));
					}
				});
				continue;
			}

			if (is_array($value) && $collection->relations->has((string) $key)) {
				$this->applyRelationFilter($query, $collection, (string) $key, $value, $tableAlias);
				continue;
			}

			if (is_array($value) && $this->isOperatorArray($value)) {
				foreach ($value as $operator => $operand) {
					$this->applyCondition($query, $collection, $tableAlias, (string) $key, (string) $operator, $operand);
				}
			}
		}
	}

	protected function applyCondition(
		object $query,
		CollectionInterface $collection,
		string $tableAlias,
		string $field,
		string $operator,
		mixed $value
	): void {
		$expression = $this->expressions->value($collection, $field, $tableAlias);
		if ($expression === null) {
			return;
		}

		match ($operator) {
			'_eq' => $query->where($expression, '=', $value),
			'_neq' => $query->where($expression, '!=', $value),
			'_lt' => $query->where($expression, '<', $value),
			'_lte' => $query->where($expression, '<=', $value),
			'_gt' => $query->where($expression, '>', $value),
			'_gte' => $query->where($expression, '>=', $value),
			'_in' => $query->where($expression, 'IN', new Parameter($this->parseArrayValue($value))),
			'_nin' => $query->where($expression, 'NOT IN', new Parameter($this->parseArrayValue($value))),
			'_null' => $query->where($expression, '=', null),
			'_nnull' => $query->where($expression, '!=', null),
			'_contains' => $query->where($expression, 'LIKE', '%' . $value . '%'),
			'_ncontains' => $query->whereNot($expression, 'LIKE', '%' . $value . '%'),
			'_starts_with' => $query->where($expression, 'LIKE', $value . '%'),
			'_ends_with' => $query->where($expression, 'LIKE', '%' . $value),
			'_between' => $this->applyBetween($query, $expression, $value, false),
			'_nbetween' => $this->applyBetween($query, $expression, $value, true),
			'_empty' => $query->where(function ($nested) use ($expression) {
				$nested->where($expression, '=', null);
				$nested->orWhere($expression, '=', '');
			}),
			'_nempty' => $query->where(function ($nested) use ($expression) {
				$nested->where($expression, '!=', null);
				$nested->where($expression, '!=', '');
			}),
			default => null,
		};
	}

	protected function applyRelationFilter(
		object $query,
		CollectionInterface $collection,
		string $relationName,
		array $filters,
		string $tableAlias
	): void {
		$relation = $collection->relations->get($relationName);
		$targetCollection = $collection->getRegistry()->getCollection($relation->getCollection());
		if ($targetCollection === null) {
			return;
		}

		$targetAlias = $this->buildAlias($tableAlias . '__' . $relationName);
		$subQuery = $this->database->select(new Fragment('1'));

		if ($relation->isJunction() && $relation instanceof M2MRelation) {
			$through = $relation->through;
			$junctionAlias = $this->buildAlias($targetAlias . '__junction');
			$subQuery
				->from($through->getCollection() . ' AS ' . $junctionAlias)
				->innerJoin($targetCollection->getTable(), $targetAlias)
				->on(
					$junctionAlias . '.' . $through->getOuterKey(),
					'=',
					$targetAlias . '.' . $this->getPrimaryKeyColumn($targetCollection)
				)
				->where(
					new Expression($junctionAlias . '.' . $through->getInnerKey()),
					'=',
					new Expression($tableAlias . '.' . $relation->getInnerKey())
				);
		} else {
			$subQuery
				->from($targetCollection->getTable() . ' AS ' . $targetAlias)
				->where(
					new Expression($targetAlias . '.' . $relation->getOuterKey()),
					'=',
					new Expression($tableAlias . '.' . $relation->getInnerKey())
				);
		}

		$this->apply($subQuery, $targetCollection, $filters, $targetAlias);
		if ($relation->getWhere() !== []) {
			$this->apply($subQuery, $targetCollection, $relation->getWhere(), $targetAlias);
		}

		$query->where($this->exists($subQuery));
	}

	protected function exists(object $subQuery): Fragment
	{
		$parameters = new QueryParameters();
		$sql = $subQuery->sqlStatement($parameters);

		return new Fragment('EXISTS (' . $sql . ')', ...$parameters->getParameters());
	}

	protected function applyBetween(object $query, FragmentInterface $expression, mixed $value, bool $negated): void
	{
		$parts = $this->parseArrayValue($value);
		if (count($parts) !== 2) {
			return;
		}

		$query->where($expression, $negated ? 'NOT BETWEEN' : 'BETWEEN', $parts[0], $parts[1]);
	}

	protected function parseArrayValue(mixed $value): array
	{
		if (is_array($value)) {
			return $value;
		}

		return array_map('trim', explode(',', (string) $value));
	}

	protected function isOperatorArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		foreach (array_keys($value) as $key) {
			if (!is_string($key) || !str_starts_with($key, '_')) {
				return false;
			}
		}

		return true;
	}

	protected function normalizeFilters(array $filters): array
	{
		$normalized = [];

		foreach ($filters as $key => $value) {
			if (($key === '_and' || $key === '_or') && is_array($value)) {
				$normalized[$key] = array_map(
					fn(mixed $group) => is_array($group) ? $this->normalizeFilters($group) : $group,
					$value
				);
				continue;
			}

			if (is_string($key) && str_contains($key, '.')) {
				$this->mergeNestedFilter($normalized, explode('.', $key), $value);
				continue;
			}

			$normalized[$key] = $value;
		}

		return $normalized;
	}

	protected function mergeNestedFilter(array &$target, array $segments, mixed $value): void
	{
		$segment = array_shift($segments);
		if ($segment === null) {
			return;
		}

		if ($segments === []) {
			if (isset($target[$segment]) && is_array($target[$segment]) && is_array($value)) {
				$target[$segment] = array_replace_recursive($target[$segment], $value);
				return;
			}

			$target[$segment] = $value;
			return;
		}

		if (!isset($target[$segment]) || !is_array($target[$segment])) {
			$target[$segment] = [];
		}

		$this->mergeNestedFilter($target[$segment], $segments, $value);
	}

	protected function fieldOrColumnToColumn(CollectionInterface $collection, string $fieldOrColumn): string
	{
		return $collection->fields->has($fieldOrColumn)
			? $collection->fields->get($fieldOrColumn)->getColumn()
			: $fieldOrColumn;
	}

	protected function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$primary = $collection->getPrimaryKey();
		if (is_array($primary)) {
			$primary = reset($primary);
		}

		return $primary->getColumn();
	}

	protected function buildAlias(string $value): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $value);
	}
}
