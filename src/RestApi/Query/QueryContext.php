<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\RestApi\Error\RestApiError;

/**
 * RestApi-owned request context for building an ON\Data SelectQuery.
 *
 * Holds protocol concerns that do not belong on SelectQuery itself:
 * limits, dynamic variables, meta, aggregate response shape, and relation aliases.
 */
final class QueryContext
{
	/**
	 * @param array<string, mixed|callable():mixed> $dynamicVariables
	 * @param list<string> $meta
	 * @param list<array{function: string, field: string, alias: string}> $aggregates
	 * @param list<array{responseName: string, alias: string}> $groupBy
	 * @param array<string, string> $relationResponseNames relation path => wire response name
	 */
	public function __construct(
		public readonly int $defaultLimit = 100,
		public readonly int $maxLimit = 1000,
		public readonly array $dynamicVariables = [],
		public readonly string $databaseType = 'sqlite',
		private array $meta = [],
		private bool $isAggregate = false,
		private array $aggregates = [],
		private array $groupBy = [],
		private array $relationResponseNames = [],
	) {
	}

	/**
	 * @return list<string>
	 */
	public function getMeta(): array
	{
		return $this->meta;
	}

	/**
	 * @param list<string> $meta
	 */
	public function setMeta(array $meta): void
	{
		$this->meta = array_values($meta);
	}

	public function isAggregate(): bool
	{
		return $this->isAggregate;
	}

	public function setAggregate(bool $isAggregate): void
	{
		$this->isAggregate = $isAggregate;
	}

	/**
	 * @return list<array{function: string, field: string, alias: string}>
	 */
	public function getAggregates(): array
	{
		return $this->aggregates;
	}

	/**
	 * @param list<array{function: string, field: string, alias: string}> $aggregates
	 */
	public function setAggregates(array $aggregates): void
	{
		$this->aggregates = array_values($aggregates);
		$this->isAggregate = $aggregates !== [];
	}

	/**
	 * @return list<array{responseName: string, alias: string}>
	 */
	public function getGroupBy(): array
	{
		return $this->groupBy;
	}

	/**
	 * @param list<array{responseName: string, alias: string}> $groupBy
	 */
	public function setGroupBy(array $groupBy): void
	{
		$this->groupBy = array_values($groupBy);
	}

	/**
	 * @return array<string, string>
	 */
	public function getRelationResponseNames(): array
	{
		return $this->relationResponseNames;
	}

	public function setRelationResponseName(string $relationPath, string $responseName): void
	{
		$existing = $this->relationResponseNames[$relationPath] ?? null;
		if ($existing !== null && $existing !== $responseName) {
			throw new RestApiError(
				"Multiple aliases for relation '{$relationPath}' are not supported on the SelectQuery read path yet.",
				'UNSUPPORTED_RELATION_ALIAS',
				$relationPath,
				400
			);
		}

		$this->relationResponseNames[$relationPath] = $responseName;
	}

	public function resolveDynamicValue(string $name): mixed
	{
		if (array_key_exists($name, $this->dynamicVariables)) {
			$value = $this->dynamicVariables[$name];

			return is_callable($value) ? $value() : $value;
		}

		$prefixed = '$' . $name;
		if (array_key_exists($prefixed, $this->dynamicVariables)) {
			$value = $this->dynamicVariables[$prefixed];

			return is_callable($value) ? $value() : $value;
		}

		return match ($name) {
			'now' => date('Y-m-d H:i:s'),
			'today' => date('Y-m-d'),
			default => null,
		};
	}
}
