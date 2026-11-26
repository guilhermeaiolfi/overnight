<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Directus;

use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\QueryContext;

final class DirectusParameterParser
{
	/**
	 * @return list<string>|null
	 */
	public function fieldPaths(mixed $fields): ?array
	{
		if ($fields === null || $fields === '') {
			return null;
		}

		$paths = array_map('strval', $this->parseArrayValue($fields, 'fields'));

		return $paths === ['*'] ? null : $paths;
	}

	/**
	 * @return list<scalar|null>
	 */
	public function parseArrayValue(mixed $value, string $name = 'query'): array
	{
		if ($value === null || $value === '') {
			return [];
		}

		if (is_array($value)) {
			foreach ($value as $item) {
				if (! is_scalar($item) && $item !== null) {
					throw self::invalidParameter($name, 'must contain only scalar values');
				}
			}

			return array_values($value);
		}

		$value = $this->scalarValue($value, $name);

		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item) => $item !== ''));
	}

	/**
	 * @return array<string, string>
	 */
	public function normalizeAliases(mixed $aliases): array
	{
		$aliases = $this->objectValue($aliases, 'alias');
		foreach ($aliases as $alias => $target) {
			if (! is_string($alias) || ! is_string($target) || $target === '') {
				throw self::invalidParameter('alias', 'must map names to relation names');
			}
		}

		return $aliases;
	}

	public function objectValue(mixed $value, string $name): array
	{
		if ($value === null) {
			return [];
		}
		if (! is_array($value)) {
			throw self::invalidParameter($name, 'must be an object');
		}

		return $value;
	}

	public function scalarValue(mixed $value, string $name): string|int|float|bool|null
	{
		if (! is_scalar($value) && $value !== null) {
			throw self::invalidParameter($name, 'must be a scalar value');
		}

		return $value;
	}

	public function integerValue(mixed $value, string $name): int
	{
		if (is_int($value) || (is_string($value) && preg_match('/^[+-]?[0-9]+$/D', $value) === 1)) {
			return (int) $value;
		}

		throw self::invalidParameter($name, 'must be an integer');
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
	 * @return list<mixed>
	 */
	public function resolveList(mixed $operand, QueryContext $context, string $name = 'filter'): array
	{
		return array_map(
			fn (mixed $item) => $this->resolveOperand($item, $context),
			$this->parseArrayValue($operand, $name),
		);
	}

	public static function invalidParameter(string $name, string $reason): RestApiError
	{
		return new RestApiError("Query parameter '{$name}' {$reason}.", 'INVALID_QUERY', $name, 400);
	}
}
