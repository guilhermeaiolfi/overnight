<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Node\AggregateSpec;
use ON\RestApi\Query\Node\BetweenFilter;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\EmptyFilter;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Query\Node\GroupBySpec;
use ON\RestApi\Query\Node\LogicalFilter;
use ON\RestApi\Query\Node\LogicalOperator;
use ON\RestApi\Query\Node\NullFilter;
use ON\RestApi\Query\Node\PaginationSpec;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationAggregateQuerySpec;
use ON\RestApi\Query\Node\RelationAggregateSelection;
use ON\RestApi\Query\Node\RelationExistsFilter;
use ON\RestApi\Query\Node\RelationQuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SearchField;
use ON\RestApi\Query\Node\SelectionNode;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\SetFilter;
use ON\RestApi\Query\Node\SetOperator;
use ON\RestApi\Query\Node\SortDirection;
use ON\RestApi\Query\Node\SortSpec;
use ON\RestApi\Query\Node\WildcardSelection;

final class DirectusQueryParser implements QueryParserInterface
{
	public function __construct(
		private readonly ExpressionParser $expressions = new ExpressionParser(),
		private readonly int $defaultLimit = 100,
		private readonly int $maxLimit = 1000,
	) {
	}

	public function parse(CollectionInterface $collection, array $input): QuerySpec
	{
		$aliases = $this->normalizeAliases($input['alias'] ?? []);
		$deep = is_array($input['deep'] ?? null) ? $input['deep'] : [];

		return new QuerySpec(
			$collection->getName(),
			$this->parseSelection($collection, $input['fields'] ?? null, $aliases, $deep, ''),
			$this->parseFilter($collection, $input['filter'] ?? [], $aliases, ''),
			$this->parseSearch($input['search'] ?? null),
			$this->parseSort($input['sort'] ?? null),
			$this->parsePagination($input),
			$this->parseAggregates($input['aggregate'] ?? []),
			$this->parseGroupBy($input['groupBy'] ?? []),
			$this->parseArrayValue($input['meta'] ?? [])
		);
	}

	private function parseSelection(
		CollectionInterface $collection,
		mixed $fields,
		array $aliases,
		array $deep,
		string $scope
	): SelectionSet {
		$fieldPaths = $this->fieldPaths($fields);
		if ($fieldPaths === null) {
			return new SelectionSet([new WildcardSelection()], false);
		}

		$fieldNodes = [];
		$relationPaths = [];
		foreach ($fieldPaths as $path) {
			if ($path === '') {
				continue;
			}

			[$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
			if ($rest === null) {
				if ($collection->fields->has($first)) {
					if (!$collection->fields->get($first)->isHidden()) {
						$fieldNodes[] = new FieldSelection(new FieldExpression($first), $first);
					}
					continue;
				}

				if ($this->relationName($collection, $first, $aliases, $scope) !== null) {
					$relationPaths[$first][] = '*';
					continue;
				}

				throw RestApiError::invalidField($first);
			}

			if ($this->relationName($collection, $first, $aliases, $scope) === null) {
				throw RestApiError::invalidField($first);
			}

			$relationPaths[$first][] = $rest;
		}

		$nodes = $fieldNodes;
		foreach ($relationPaths as $responseName => $subPaths) {
			$nodes[] = $this->parseRelationSelection($collection, $responseName, $subPaths, $aliases, $deep, $scope);
		}

		return new SelectionSet($nodes, true);
	}

	/**
	 * @param list<string> $subPaths
	 */
	private function parseRelationSelection(
		CollectionInterface $collection,
		string $responseName,
		array $subPaths,
		array $aliases,
		array $deep,
		string $scope
	): SelectionNode {
		$relationName = $this->relationName($collection, $responseName, $aliases, $scope);
		if ($relationName === null) {
			throw RestApiError::invalidField($responseName);
		}

		$relation = $collection->relations->get($relationName);
		$targetCollection = $collection->getRegistry()->getCollection($relation->getCollection());
		if ($targetCollection === null) {
			throw RestApiError::invalidField($responseName);
		}

		$relationDeep = is_array($deep[$responseName] ?? null) ? $deep[$responseName] : [];
		$childScope = $this->joinScope($scope, $responseName);

		if (isset($relationDeep['_aggregate']) || isset($relationDeep['_groupBy'])) {
			return new RelationAggregateSelection(
				$responseName,
				$relationName,
				$targetCollection->getName(),
				new RelationAggregateQuerySpec(
					$this->parseFilter($targetCollection, $relationDeep['_filter'] ?? [], $aliases, $childScope),
					$this->parseSearch($relationDeep['_search'] ?? null),
					$this->parseGroupBy($relationDeep['_groupBy'] ?? []),
					$this->parseAggregates($relationDeep['_aggregate'] ?? []),
					$this->parseSort($relationDeep['_sort'] ?? null),
					$this->parsePagination($relationDeep, '_')
				)
			);
		}

		$selection = $this->parseSelection($targetCollection, $subPaths, $aliases, $relationDeep, $childScope);

		return new RelationSelection(
			$responseName,
			$relationName,
			$targetCollection->getName(),
			new RelationQuerySpec(
				$selection,
				$this->parseFilter($targetCollection, $relationDeep['_filter'] ?? [], $aliases, $childScope),
				$this->parseSearch($relationDeep['_search'] ?? null),
				$this->parseSort($relationDeep['_sort'] ?? null),
				$this->parsePagination($relationDeep, '_')
			)
		);
	}

	private function parseFilter(CollectionInterface $collection, mixed $filters, array $aliases, string $scope): ?FilterNode
	{
		if (!is_array($filters) || $filters === []) {
			return null;
		}

		$nodes = [];
		foreach ($filters as $key => $value) {
			if ($key === '_and' || $key === '_or') {
				if (!is_array($value)) {
					continue;
				}

				$children = [];
				foreach ($value as $group) {
					$child = $this->parseFilter($collection, $group, $aliases, $scope);
					if ($child !== null) {
						$children[] = $child;
					}
				}

				if ($children !== []) {
					$nodes[] = new LogicalFilter($key === '_and' ? LogicalOperator::And : LogicalOperator::Or, $children);
				}
				continue;
			}

			if (!is_string($key) || !is_array($value)) {
				continue;
			}

			$relationName = $this->relationName($collection, $key, $aliases, $scope);
			if ($relationName !== null && !$this->isOperatorArray($value)) {
				$relation = $collection->relations->get($relationName);
				$targetCollection = $collection->getRegistry()->getCollection($relation->getCollection());
				if ($targetCollection === null) {
					continue;
				}

				$child = $this->parseFilter($targetCollection, $value, $aliases, $this->joinScope($scope, $key));
				if ($child !== null) {
					$nodes[] = new RelationExistsFilter($key, $relationName, $targetCollection->getName(), $child);
				}
				continue;
			}

			foreach ($value as $operator => $operand) {
				if (!is_string($operator)) {
					continue;
				}
				$node = $this->parseOperator($key, $operator, $operand);
				if ($node !== null) {
					$nodes[] = $node;
				}
			}
		}

		if ($nodes === []) {
			return null;
		}

		return count($nodes) === 1 ? $nodes[0] : new LogicalFilter(LogicalOperator::And, $nodes);
	}

	private function parseOperator(string $field, string $operator, mixed $operand): ?FilterNode
	{
		$left = $this->expressions->parseExpression($field);

		return match ($operator) {
			'_eq' => new ComparisonFilter($left, ComparisonOperator::Eq, $this->expressions->parseValue($operand)),
			'_neq' => new ComparisonFilter($left, ComparisonOperator::Neq, $this->expressions->parseValue($operand)),
			'_lt' => new ComparisonFilter($left, ComparisonOperator::Lt, $this->expressions->parseValue($operand)),
			'_lte' => new ComparisonFilter($left, ComparisonOperator::Lte, $this->expressions->parseValue($operand)),
			'_gt' => new ComparisonFilter($left, ComparisonOperator::Gt, $this->expressions->parseValue($operand)),
			'_gte' => new ComparisonFilter($left, ComparisonOperator::Gte, $this->expressions->parseValue($operand)),
			'_contains' => new ComparisonFilter($left, ComparisonOperator::Contains, $this->expressions->parseValue($operand)),
			'_ncontains' => new ComparisonFilter($left, ComparisonOperator::NotContains, $this->expressions->parseValue($operand)),
			'_starts_with' => new ComparisonFilter($left, ComparisonOperator::StartsWith, $this->expressions->parseValue($operand)),
			'_ends_with' => new ComparisonFilter($left, ComparisonOperator::EndsWith, $this->expressions->parseValue($operand)),
			'_in' => new SetFilter($left, SetOperator::In, $this->parseValueList($operand)),
			'_nin' => new SetFilter($left, SetOperator::NotIn, $this->parseValueList($operand)),
			'_between' => $this->parseBetween($left, $operand, false),
			'_nbetween' => $this->parseBetween($left, $operand, true),
			'_null' => new NullFilter($left),
			'_nnull' => new NullFilter($left, true),
			'_empty' => new EmptyFilter($left),
			'_nempty' => new EmptyFilter($left, true),
			default => null,
		};
	}

	private function parseBetween(\ON\RestApi\Query\Node\ExpressionNode $left, mixed $operand, bool $negated): ?BetweenFilter
	{
		$values = $this->parseArrayValue($operand);
		if (count($values) !== 2) {
			return null;
		}

		return new BetweenFilter($left, $this->expressions->parseValue($values[0]), $this->expressions->parseValue($values[1]), $negated);
	}

	private function parseSort(mixed $sort): array
	{
		$items = $this->parseArrayValue($sort);
		$result = [];
		foreach ($items as $item) {
			$item = trim((string) $item);
			if ($item === '') {
				continue;
			}

			$direction = SortDirection::Asc;
			if (str_starts_with($item, '-')) {
				$direction = SortDirection::Desc;
				$item = substr($item, 1);
			}

			$result[] = new SortSpec($this->expressions->parseExpression($item), $direction);
		}

		return $result;
	}

	private function parsePagination(array $input, string $prefix = ''): ?PaginationSpec
	{
		$limitKey = $prefix . 'limit';
		$offsetKey = $prefix . 'offset';
		$pageKey = $prefix . 'page';

		if (!isset($input[$limitKey]) && !isset($input[$offsetKey]) && !isset($input[$pageKey])) {
			return null;
		}

		$limit = isset($input[$limitKey]) ? (int) $input[$limitKey] : $this->defaultLimit;
		$limit = min(max(1, $limit), $this->maxLimit);
		$offset = isset($input[$offsetKey]) ? max(0, (int) $input[$offsetKey]) : 0;
		if (isset($input[$pageKey])) {
			$offset = (max(1, (int) $input[$pageKey]) - 1) * $limit;
		}

		return new PaginationSpec($limit, $offset);
	}

	private function parseAggregates(mixed $aggregates): array
	{
		if (!is_array($aggregates)) {
			return [];
		}

		$result = [];
		foreach ($aggregates as $function => $fields) {
			foreach ($this->parseArrayValue($fields) as $field) {
				$field = (string) $field;
				$result[] = new AggregateSpec(
					$this->expressions->parseAggregate((string) $function, $field),
					(string) $function,
					$field
				);
			}
		}

		return $result;
	}

	private function parseGroupBy(mixed $groupBy): array
	{
		$result = [];
		foreach ($this->parseArrayValue($groupBy) as $field) {
			$field = (string) $field;
			if ($field === '') {
				continue;
			}
			$result[] = new GroupBySpec($this->expressions->parseExpression($field), $this->expressions->alias($field), $field);
		}

		return $result;
	}

	private function parseSearch(mixed $search): ?SearchField
	{
		if ($search === null || $search === '') {
			return null;
		}

		return new SearchField((string) $search);
	}

	private function fieldPaths(mixed $fields): ?array
	{
		if ($fields === null || $fields === '' || $fields === '*') {
			return null;
		}

		if (is_array($fields)) {
			$paths = array_values(array_map('strval', $fields));
			return $paths === ['*'] ? null : $paths;
		}

		return array_values(array_filter(array_map('trim', explode(',', (string) $fields)), fn(string $field) => $field !== ''));
	}

	private function parseArrayValue(mixed $value): array
	{
		if ($value === null || $value === '') {
			return [];
		}

		if (is_array($value)) {
			return array_values($value);
		}

		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn(string $item) => $item !== ''));
	}

	private function parseValueList(mixed $value): array
	{
		return array_map(fn(mixed $item) => $this->expressions->parseValue($item), $this->parseArrayValue($value));
	}

	private function isOperatorArray(array $value): bool
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

	private function normalizeAliases(mixed $aliases): array
	{
		if (!is_array($aliases)) {
			return [];
		}

		$normalized = [];
		foreach ($aliases as $alias => $target) {
			if (!is_string($alias) || !is_string($target)) {
				continue;
			}
			$normalized[$alias] = $target;
		}

		return $normalized;
	}

	private function relationName(CollectionInterface $collection, string $responseName, array $aliases, string $scope): ?string
	{
		$aliasKey = $this->joinScope($scope, $responseName);
		$relationName = $aliases[$aliasKey] ?? ($aliases[$responseName] ?? $responseName);

		return $collection->relations->has($relationName) ? $relationName : null;
	}

	private function joinScope(string $scope, string $name): string
	{
		return $scope === '' ? $name : $scope . '.' . $name;
	}
}
