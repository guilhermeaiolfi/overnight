<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;

class SqlFilterParser
{
	/**
	 * Parse Directus-style filter array into SQL WHERE clause + bound values.
	 *
	 * Input format: ['field' => ['_operator' => 'value'], '_or' => [...]]
	 * Output: ['sql' => 'WHERE ...', 'values' => [...]]
	 */
	public function parse(CollectionInterface $collection, array $filters): array
	{
		if (empty($filters)) {
			return ['sql' => '', 'values' => []];
		}

		$filters = $this->normalizeFilters($filters);
		$values = [];
		$conditions = $this->parseGroup($collection, $filters, $values, $collection->getTable());

		if (empty($conditions)) {
			return ['sql' => '', 'values' => []];
		}

		return [
			'sql' => 'WHERE ' . implode(' AND ', $conditions),
			'values' => $values,
		];
	}

	protected function parseGroup(CollectionInterface $collection, array $filters, array &$values, string $tableAlias): array
	{
		$conditions = [];

		foreach ($filters as $key => $value) {
			if ($key === '_and' && is_array($value)) {
				$sub = $this->parseLogicalGroup($collection, $value, $values, 'AND', $tableAlias);
				if ($sub !== null) {
					$conditions[] = $sub;
				}
			} elseif ($key === '_or' && is_array($value)) {
				$sub = $this->parseLogicalGroup($collection, $value, $values, 'OR', $tableAlias);
				if ($sub !== null) {
					$conditions[] = $sub;
				}
			} elseif ($this->isRelationFilter($collection, $key, $value)) {
				$condition = $this->parseRelationCondition($collection, $key, $value, $values, $tableAlias);
				if ($condition !== null) {
					$conditions[] = $condition;
				}
			} elseif (is_array($value) && $this->isOperatorArray($value)) {
				foreach ($value as $operator => $operand) {
					$condition = $this->parseCondition($collection, $tableAlias, $key, $operator, $operand, $values);
					if ($condition !== null) {
						$conditions[] = $condition;
					}
				}
			}
		}

		return $conditions;
	}

	protected function parseLogicalGroup(CollectionInterface $collection, array $groups, array &$values, string $logic, string $tableAlias): ?string
	{
		$parts = [];

		foreach ($groups as $group) {
			if (!is_array($group)) {
				continue;
			}
			$sub = $this->parseGroup($collection, $this->normalizeFilters($group), $values, $tableAlias);
			if (!empty($sub)) {
				$parts[] = '(' . implode(' AND ', $sub) . ')';
			}
		}

		if (empty($parts)) {
			return null;
		}

		return '(' . implode(" {$logic} ", $parts) . ')';
	}

	protected function parseCondition(CollectionInterface $collection, string $tableAlias, string $field, string $operator, mixed $value, array &$values): ?string
	{
		if (!$this->isValidField($collection, $field)) {
			return null;
		}

		$quotedField = $this->qualifyColumn($tableAlias, $collection->fields->get($field)->getColumn());

		return match ($operator) {
			'_eq' => $this->comparisonOp($quotedField, '=', $value, $values),
			'_neq' => $this->comparisonOp($quotedField, '!=', $value, $values),
			'_lt' => $this->comparisonOp($quotedField, '<', $value, $values),
			'_lte' => $this->comparisonOp($quotedField, '<=', $value, $values),
			'_gt' => $this->comparisonOp($quotedField, '>', $value, $values),
			'_gte' => $this->comparisonOp($quotedField, '>=', $value, $values),
			'_in' => $this->arrayOp($quotedField, 'IN', $value, $values),
			'_nin' => $this->arrayOp($quotedField, 'NOT IN', $value, $values),
			'_null' => "{$quotedField} IS NULL",
			'_nnull' => "{$quotedField} IS NOT NULL",
			'_contains' => $this->likeOp($quotedField, "%{$value}%", false, $values),
			'_ncontains' => $this->likeOp($quotedField, "%{$value}%", true, $values),
			'_starts_with' => $this->likeOp($quotedField, "{$value}%", false, $values),
			'_ends_with' => $this->likeOp($quotedField, "%{$value}", false, $values),
			'_between' => $this->betweenOp($quotedField, $value, false, $values),
			'_nbetween' => $this->betweenOp($quotedField, $value, true, $values),
			'_empty' => "({$quotedField} IS NULL OR {$quotedField} = '')",
			'_nempty' => "({$quotedField} IS NOT NULL AND {$quotedField} != '')",
			default => null,
		};
	}

	protected function parseRelationCondition(
		CollectionInterface $collection,
		string $relationName,
		mixed $value,
		array &$values,
		string $tableAlias
	): ?string {
		if (!$collection->relations->has($relationName) || !is_array($value)) {
			return null;
		}

		$relation = $collection->relations->get($relationName);
		$targetCollection = $collection->getRegistry()->getCollection($relation->getCollection());

		if ($targetCollection === null) {
			return null;
		}

		$targetAlias = $this->buildAlias($tableAlias . '__' . $relationName);
		$conditions = [];

		if ($relation->isJunction() && $relation instanceof \ON\ORM\Definition\Relation\M2MRelation) {
			$through = $relation->through;
			$junctionAlias = $this->buildAlias($targetAlias . '__junction');
			$parentColumn = $this->fieldOrColumnToColumn($collection, (string) $relation->getInnerKey());
			$targetColumn = $this->getPrimaryKeyColumn($targetCollection);
			$junctionInnerColumn = (string) $through->getInnerKey();
			$junctionOuterColumn = (string) $through->getOuterKey();

			$conditions[] = $this->qualifyColumn($junctionAlias, $junctionInnerColumn)
				. ' = '
				. $this->qualifyColumn($tableAlias, $parentColumn);

			$conditions[] = $this->qualifyColumn($junctionAlias, $junctionOuterColumn)
				. ' = '
				. $this->qualifyColumn($targetAlias, $targetColumn);

			$relationConditions = $this->parseGroup($targetCollection, $this->normalizeFilters($value), $values, $targetAlias);
			$relationConditions = array_merge($relationConditions, $this->parseRelationWhere($targetCollection, $relation->getWhere(), $values, $targetAlias));

			if (empty($relationConditions)) {
				return null;
			}

			$conditions = array_merge($conditions, $relationConditions);

			return sprintf(
				'EXISTS (SELECT 1 FROM %s %s INNER JOIN %s %s ON %s WHERE %s)',
				$this->quoteIdentifier($through->getCollection()),
				$this->quoteIdentifier($junctionAlias),
				$this->quoteIdentifier($targetCollection->getTable()),
				$this->quoteIdentifier($targetAlias),
				$this->qualifyColumn($junctionAlias, $junctionOuterColumn) . ' = ' . $this->qualifyColumn($targetAlias, $targetColumn),
				implode(' AND ', $conditions)
			);
		}

		$parentColumn = $this->fieldOrColumnToColumn($collection, (string) $relation->getInnerKey());
		$targetColumn = $this->fieldOrColumnToColumn($targetCollection, (string) $relation->getOuterKey());

		$conditions[] = $this->qualifyColumn($targetAlias, $targetColumn)
			. ' = '
			. $this->qualifyColumn($tableAlias, $parentColumn);

		$relationConditions = $this->parseGroup($targetCollection, $this->normalizeFilters($value), $values, $targetAlias);
		$relationConditions = array_merge($relationConditions, $this->parseRelationWhere($targetCollection, $relation->getWhere(), $values, $targetAlias));

		if (empty($relationConditions)) {
			return null;
		}

		$conditions = array_merge($conditions, $relationConditions);

		return sprintf(
			'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
			$this->quoteIdentifier($targetCollection->getTable()),
			$this->quoteIdentifier($targetAlias),
			implode(' AND ', $conditions)
		);
	}

	protected function parseRelationWhere(CollectionInterface $collection, array $filters, array &$values, string $tableAlias): array
	{
		if ($filters === []) {
			return [];
		}

		return $this->parseGroup($collection, $this->normalizeFilters($filters), $values, $tableAlias);
	}

	protected function comparisonOp(string $field, string $op, mixed $value, array &$values): string
	{
		$values[] = $value;
		return "{$field} {$op} ?";
	}

	protected function arrayOp(string $field, string $op, mixed $value, array &$values): ?string
	{
		$items = is_array($value) ? $value : explode(',', (string) $value);
		$items = array_map('trim', $items);

		if (empty($items)) {
			return null;
		}

		$placeholders = implode(', ', array_fill(0, count($items), '?'));
		foreach ($items as $item) {
			$values[] = $item;
		}

		return "{$field} {$op} ({$placeholders})";
	}

	protected function likeOp(string $field, string $pattern, bool $negate, array &$values): string
	{
		$values[] = $pattern;
		$op = $negate ? 'NOT LIKE' : 'LIKE';
		return "{$field} {$op} ?";
	}

	protected function betweenOp(string $field, mixed $value, bool $negate, array &$values): ?string
	{
		$parts = is_array($value) ? $value : explode(',', (string) $value);
		$parts = array_map('trim', $parts);

		if (count($parts) !== 2) {
			return null;
		}

		$values[] = $parts[0];
		$values[] = $parts[1];
		$op = $negate ? 'NOT BETWEEN' : 'BETWEEN';
		return "{$field} {$op} ? AND ?";
	}

	public function quoteIdentifier(string $identifier): string
	{
		$sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
		return "`{$sanitized}`";
	}

	protected function isValidField(CollectionInterface $collection, string $fieldName): bool
	{
		return $collection->fields->has($fieldName);
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

	protected function isRelationFilter(CollectionInterface $collection, string $key, mixed $value): bool
	{
		return is_array($value) && $collection->relations->has($key);
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
		if ($collection->fields->has($fieldOrColumn)) {
			return $collection->fields->get($fieldOrColumn)->getColumn();
		}

		return $fieldOrColumn;
	}

	protected function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$primary = $collection->getPrimaryKey();

		if (is_array($primary)) {
			$primary = reset($primary);
		}

		return $primary->getColumn();
	}

	protected function qualifyColumn(string $tableAlias, string $column): string
	{
		return $this->quoteIdentifier($tableAlias) . '.' . $this->quoteIdentifier($column);
	}

	protected function buildAlias(string $value): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $value);
	}
}
