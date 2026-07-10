<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Directus;

use ON\RestApi\Query\QueryContext;

final class DirectusParameterParser
{
	/**
	 * @return list<string>
	 */
	public function fieldPaths(mixed $fields): ?array
	{
		if ($fields === null || $fields === '' || $fields === '*') {
			return null;
		}

		if (is_array($fields)) {
			$paths = array_values(array_map('strval', $fields));

			return $paths === ['*'] ? null : $paths;
		}

		return array_values(array_filter(array_map('trim', explode(',', (string) $fields)), fn (string $field) => $field !== ''));
	}

	public function parseArrayValue(mixed $value): array
	{
		if ($value === null || $value === '') {
			return [];
		}

		if (is_array($value)) {
			return array_values($value);
		}

		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item) => $item !== ''));
	}

	/**
	 * @return array<string, string>
	 */
	public function normalizeAliases(mixed $aliases): array
	{
		if (! is_array($aliases)) {
			return [];
		}

		$normalized = [];
		foreach ($aliases as $alias => $target) {
			if (! is_string($alias) || ! is_string($target)) {
				continue;
			}
			$normalized[$alias] = $target;
		}

		return $normalized;
	}

	public function resolveOperand(mixed $operand, QueryContext $context): mixed
	{
		if (is_string($operand) && str_starts_with($operand, '$')) {
			$resolved = $context->resolveDynamicValue(substr($operand, 1));

			return $resolved ?? $operand;
		}

		return $operand;
	}

	/**
	 * @return non-empty-list<mixed>
	 */
	public function resolveList(mixed $operand, QueryContext $context): array
	{
		$values = array_map(
			fn (mixed $item) => $this->resolveOperand($item, $context),
			$this->parseArrayValue($operand),
		);
		if ($values === []) {
			$values = [null];
		}

		return $values;
	}
}
