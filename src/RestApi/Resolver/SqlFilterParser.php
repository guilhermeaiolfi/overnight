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

		$values = [];
		$conditions = $this->parseGroup($collection, $filters, $values);

		if (empty($conditions)) {
			return ['sql' => '', 'values' => []];
		}

		return [
			'sql' => 'WHERE ' . implode(' AND ', $conditions),
			'values' => $values,
		];
	}

	protected function parseGroup(CollectionInterface $collection, array $filters, array &$values): array
	{
		$conditions = [];

		foreach ($filters as $key => $value) {
			if ($key === '_and' && is_array($value)) {
				$sub = $this->parseLogicalGroup($collection, $value, $values, 'AND');
				if ($sub !== null) {
					$conditions[] = $sub;
				}
			} elseif ($key === '_or' && is_array($value)) {
				$sub = $this->parseLogicalGroup($collection, $value, $values, 'OR');
				if ($sub !== null) {
					$conditions[] = $sub;
				}
			} elseif (is_array($value)) {
				foreach ($value as $operator => $operand) {
					$condition = $this->parseCondition($collection, $key, $operator, $operand, $values);
					if ($condition !== null) {
						$conditions[] = $condition;
					}
				}
			}
		}

		return $conditions;
	}

	protected function parseLogicalGroup(CollectionInterface $collection, array $groups, array &$values, string $logic): ?string
	{
		$parts = [];

		foreach ($groups as $group) {
			if (!is_array($group)) {
				continue;
			}
			$sub = $this->parseGroup($collection, $group, $values);
			if (!empty($sub)) {
				$parts[] = '(' . implode(' AND ', $sub) . ')';
			}
		}

		if (empty($parts)) {
			return null;
		}

		return '(' . implode(" {$logic} ", $parts) . ')';
	}

	protected function parseCondition(CollectionInterface $collection, string $field, string $operator, mixed $value, array &$values): ?string
	{
		if (!$this->isValidField($collection, $field)) {
			return null;
		}

		$quotedField = $this->quoteIdentifier($collection->fields->get($field)->getColumn());

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
		$sanitized = preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
		return "`{$sanitized}`";
	}

	protected function isValidField(CollectionInterface $collection, string $fieldName): bool
	{
		return $collection->fields->has($fieldName);
	}
}
