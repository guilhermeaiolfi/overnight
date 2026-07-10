<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Selection\SelectionTag;
use function ON\Data\Query\x;
use ON\RestApi\Query\Directus\DirectusParameterParser;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Directus\DirectusFunctionParser;
use ON\RestApi\Query\QueryContext;
use Throwable;

final class DirectusQueryParser implements QueryParserInterface
{
	private const DATE_FUNCTIONS = ['year', 'month', 'day', 'hour', 'date'];

	public function __construct(
		private readonly DataRuntime $runtime,
		private readonly DirectusFunctionParser $functions = new DirectusFunctionParser(),
		private readonly DirectusParameterParser $parameters = new DirectusParameterParser(),
	) {
	}

	public function parse(
		CollectionInterface $collection,
		array $parameters,
		QueryContext $context,
	): SelectQuery {
		$query = $this->runtime->query($collection);

		$aliases = $this->parameters->normalizeAliases($parameters['alias'] ?? []);
		$deep = is_array($parameters['deep'] ?? null) ? $parameters['deep'] : [];

		$aggregates = is_array($parameters['aggregate'] ?? null) ? $parameters['aggregate'] : [];
		if ($aggregates !== []) {
			$this->applyAggregateQuery($query, $collection, $parameters, $context, $aliases);

			return $query;
		}

		$this->applySelection($query, $collection, $parameters['fields'] ?? null, $aliases, $deep, '', $context);
		$this->applyFilter($query, $collection, $parameters['filter'] ?? [], $aliases, '', $context);
		$this->applySearch($query, $collection, $parameters['search'] ?? null);
		$this->applySort($query, $collection, $parameters['sort'] ?? null, $context);
		$this->applyPagination($query, $parameters, $context);
		$context->setMeta($this->parameters->parseArrayValue($parameters['meta'] ?? []));

		return $query;
	}

	private function applyAggregateQuery(
		SelectQuery $query,
		CollectionInterface $collection,
		array $parameters,
		QueryContext $context,
		array $aliases,
	): void {
		$aggregateMeta = [];
		$selects = [];

		$groupByMeta = [];
		foreach ($this->parameters->parseArrayValue($parameters['groupBy'] ?? []) as $field) {
			$field = (string) $field;
			if ($field === '') {
				continue;
			}
			$alias = $this->functions->alias($field);
			$expression = $this->valueExpression($query, $collection, $field, $context);
			$selects[] = $expression->as($alias);
			$groupByMeta[] = ['responseName' => $field, 'alias' => $alias];
			$query->groupBy($expression);
		}

		foreach ($parameters['aggregate'] as $function => $fields) {
			foreach ($this->parameters->parseArrayValue($fields) as $field) {
				$field = (string) $field;
				$alias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $function . '_' . $field);
				$selects[] = $this->aggregateExpression($query, $collection, (string) $function, $field, $context)->as($alias);
				$aggregateMeta[] = [
					'function' => (string) $function,
					'field' => $field,
					'alias' => $alias,
				];
			}
		}

		if ($selects !== []) {
			$query->select(...$selects);
		}

		$this->applyFilter($query, $collection, $parameters['filter'] ?? [], $aliases, '', $context);
		$this->applySearch($query, $collection, $parameters['search'] ?? null);
		$context->setAggregates($aggregateMeta);
		$context->setGroupBy($groupByMeta);
		$context->setMeta($this->parameters->parseArrayValue($parameters['meta'] ?? []));
	}

	private function applySelection(
		SelectQuery $query,
		CollectionInterface $collection,
		mixed $fields,
		array $aliases,
		array $deep,
		string $scope,
		QueryContext $context,
		?RelationRef $relationRef = null,
	): void {
		$fieldPaths = $this->parameters->fieldPaths($fields);
		if ($fieldPaths === null) {
			if ($relationRef !== null) {
				$relationRef->load();
			}
			$this->requirePrimaryKeys($query, $collection, $relationRef);

			return;
		}

		if ($fieldPaths === [] && $relationRef === null) {
			// Explicit empty fields=[]: clear default * and expose no public columns.
			$pkRefs = [];
			foreach ($this->primaryKeyFields($collection) as $field) {
				$pkRefs[] = $query->field($field->getName());
			}
			if ($pkRefs !== []) {
				$query->select(...$pkRefs);
				foreach ($pkRefs as $ref) {
					$query->require($ref, SelectionTag::INTERNAL);
				}
			}

			return;
		}

		if ($fieldPaths === [] && $relationRef !== null) {
			return;
		}

		$rootFields = [];
		$relationPaths = [];
		foreach ($fieldPaths as $path) {
			if ($path === '') {
				continue;
			}

			[$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
			if ($rest === null) {
				if ($collection->fields->has($first)) {
					if (! $collection->fields->get($first)->isHidden()) {
						$rootFields[] = $first;
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

		if ($relationRef === null) {
			if ($rootFields !== []) {
				$expressions = array_map(fn (string $name) => $query->field($name), array_values(array_unique($rootFields)));
				$query->select(...$expressions);
			} elseif ($relationPaths !== []) {
				$pkExpressions = [];
				foreach ($this->primaryKeyFields($collection) as $field) {
					$pkExpressions[] = $query->field($field->getName());
				}
				if ($pkExpressions !== []) {
					$query->select(...$pkExpressions);
				}
			}
			$this->requirePrimaryKeys($query, $collection, null);
		} else {
			if ($rootFields !== []) {
				$relationRef->fields(...array_values(array_unique($rootFields)));
			} else {
				$relationRef->load();
			}
			$this->requirePrimaryKeys($query, $collection, $relationRef);
		}

		foreach ($relationPaths as $responseName => $subPaths) {
			$this->applyRelationSelection(
				$query,
				$collection,
				$responseName,
				$subPaths,
				$aliases,
				$deep,
				$scope,
				$context,
				$relationRef,
			);
		}
	}

	/**
	 * @param list<string> $subPaths
	 */
	private function applyRelationSelection(
		SelectQuery $query,
		CollectionInterface $collection,
		string $responseName,
		array $subPaths,
		array $aliases,
		array $deep,
		string $scope,
		QueryContext $context,
		?RelationRef $parentRelation,
	): void {
		$relationName = $this->relationName($collection, $responseName, $aliases, $scope);
		if ($relationName === null) {
			throw RestApiError::invalidField($responseName);
		}

		$relation = $collection->relations->get($relationName);
		$targetCollection = $relation->getCollection();
		if ($targetCollection === null) {
			throw RestApiError::invalidField($responseName);
		}

		$relationPath = $this->joinScope($scope, $relationName);
		if ($responseName !== $relationName) {
			$context->setRelationResponseName($relationPath, $responseName);
		}

		$rel = $parentRelation === null
			? $query->relation($relationName)
			: $parentRelation->relation($relationName);

		$relationDeep = is_array($deep[$responseName] ?? null) ? $deep[$responseName] : [];
		$childScope = $this->joinScope($scope, $responseName);

		if (isset($relationDeep['_aggregate']) || isset($relationDeep['_groupBy'])) {
			throw new RestApiError(
				"Relation aggregate '{$responseName}' is not supported by the SelectQuery read path yet.",
				'UNSUPPORTED_RELATION_AGGREGATE',
				$responseName,
				400
			);
		}

		$this->applySelection($query, $targetCollection, $subPaths, $aliases, $relationDeep, $childScope, $context, $rel);
		$this->applyRelationDeep($query, $rel, $targetCollection, $relationDeep, $aliases, $childScope, $context);
	}

	private function applyRelationDeep(
		SelectQuery $rootQuery,
		RelationRef $rel,
		CollectionInterface $targetCollection,
		array $deep,
		array $aliases,
		string $scope,
		QueryContext $context,
	): void {
		if (isset($deep['_filter'])) {
			$conditions = $this->buildConditions($rootQuery, $targetCollection, $deep['_filter'], $aliases, $scope, $context, $rel);
			if ($conditions !== []) {
				$rel->where(...$conditions);
			}
		}

		if (isset($deep['_search']) && $deep['_search'] !== null && $deep['_search'] !== '') {
			$searchConditions = $this->searchConditions($rel, $targetCollection, (string) $deep['_search']);
			if ($searchConditions !== null) {
				$rel->where($searchConditions);
			}
		}

		$sorts = [];
		foreach ($this->parameters->parseArrayValue($deep['_sort'] ?? null) as $item) {
			$item = trim((string) $item);
			if ($item === '') {
				continue;
			}
			$desc = str_starts_with($item, '-');
			$field = $desc ? substr($item, 1) : $item;
			$expression = $this->relationValueExpression($rel, $targetCollection, $field, $context);
			$sorts[] = $desc ? $expression->desc() : $expression->asc();
		}
		if ($sorts !== []) {
			// FirstOfMany ordering is definition-owned; selection-level orderBy is rejected by ondata.
			if (! $rel->getDefinition() instanceof \ON\Data\Definition\Relation\FirstOfManyRelation) {
				$rel->orderBy(...$sorts);
			}
		}

		if (isset($deep['_limit']) || isset($deep['_offset']) || isset($deep['_page'])) {
			$limit = isset($deep['_limit']) ? (int) $deep['_limit'] : $context->defaultLimit;
			$limit = min(max(1, $limit), $context->maxLimit);
			$offset = isset($deep['_offset']) ? max(0, (int) $deep['_offset']) : 0;
			if (isset($deep['_page'])) {
				$offset = (max(1, (int) $deep['_page']) - 1) * $limit;
			}
			if ($sorts === []) {
				foreach ($targetCollection->getPrimaryKeyFields() as $pk) {
					$sorts[] = $rel->field($pk->getName())->asc();
				}
				if ($sorts !== []) {
					$rel->orderBy(...$sorts);
				}
			}
			$rel->limit($limit);
			if ($offset > 0) {
				$rel->offset($offset);
			}
		}
	}

	private function applyFilter(
		SelectQuery $query,
		CollectionInterface $collection,
		mixed $filters,
		array $aliases,
		string $scope,
		QueryContext $context,
	): void {
		$conditions = $this->buildConditions($query, $collection, $filters, $aliases, $scope, $context, null);
		if ($conditions !== []) {
			$query->where(...$conditions);
		}
	}

	/**
	 * @return list<ConditionInterface>
	 */
	private function buildConditions(
		SelectQuery $rootQuery,
		CollectionInterface $collection,
		mixed $filters,
		array $aliases,
		string $scope,
		QueryContext $context,
		?RelationRef $relationSource,
	): array {
		if (! is_array($filters) || $filters === []) {
			return [];
		}

		$nodes = [];
		foreach ($filters as $key => $value) {
			if (
				is_string($key)
				&& str_contains($key, '.')
				&& $this->relationName($collection, $key, $aliases, $scope) === null
			) {
				[$relationKey, $nestedKey] = explode('.', $key, 2);
				$value = [$nestedKey => $value];
				$key = $relationKey;
			}

			if ($key === '_and' || $key === '_or') {
				if (! is_array($value)) {
					continue;
				}

				$children = [];
				foreach ($value as $group) {
					$childConditions = $this->buildConditions($rootQuery, $collection, $group, $aliases, $scope, $context, $relationSource);
					if ($childConditions === []) {
						continue;
					}
					$children[] = count($childConditions) === 1
						? $childConditions[0]
						: x()->and(...$childConditions);
				}

				if ($children !== []) {
					$nodes[] = $key === '_and' ? x()->and(...$children) : x()->or(...$children);
				}

				continue;
			}

			if (! is_string($key) || ! is_array($value)) {
				continue;
			}

			$relationName = $this->relationName($collection, $key, $aliases, $scope);
			if ($relationName !== null && ! $this->isOperatorArray($value)) {
				$relation = $collection->relations->get($relationName);
				$targetCollection = $relation->getCollection();
				if ($targetCollection === null) {
					continue;
				}

				$exists = $this->buildRelationExists(
					$rootQuery,
					$collection,
					$relation,
					$value,
					$aliases,
					$this->joinScope($scope, $key),
					$context,
					$relationSource,
				);
				if ($exists !== null) {
					$nodes[] = $exists;
				}

				continue;
			}

			foreach ($value as $operator => $operand) {
				if (! is_string($operator)) {
					continue;
				}
				$condition = $this->buildOperatorCondition(
					$rootQuery,
					$collection,
					$key,
					$operator,
					$operand,
					$context,
					$relationSource,
				);
				if ($condition !== null) {
					$nodes[] = $condition;
				}
			}
		}

		return $nodes;
	}

	private function buildRelationExists(
		SelectQuery $rootQuery,
		CollectionInterface $sourceCollection,
		RelationInterface $relation,
		array $nestedFilter,
		array $aliases,
		string $scope,
		QueryContext $context,
		?RelationRef $parentRelation,
	): ?ConditionInterface {
		$targetCollection = $relation->getCollection();
		if ($targetCollection === null) {
			return null;
		}

		$parentSource = $parentRelation ?? $rootQuery;

		if ($relation->isJunction() && $relation instanceof M2MRelation) {
			$through = $relation->getThrough();
			$throughCollection = $through->getCollection();
			$junction = $rootQuery->related($throughCollection);
			foreach ($through->getInnerKeys() as $index => $throughInnerKey) {
				$sourceKey = $relation->getInnerKeys()[$index];
				$junction->where(x()->eq(
					$junction->field($throughInnerKey),
					$parentSource->field($sourceKey),
				));
			}

			$target = $rootQuery->related($targetCollection);
			foreach ($through->getOuterKeys() as $index => $throughOuterKey) {
				$targetKey = $relation->getOuterKeys()[$index];
				$target->where(x()->eq(
					$target->field($targetKey),
					$junction->field($throughOuterKey),
				));
			}

			$nested = $this->buildConditionsOnRelated($target, $targetCollection, $nestedFilter, $aliases, $scope, $context);
			if ($nested !== []) {
				$target->where(...$nested);
			}

			$junction->where(x()->exists($target));

			return x()->exists($junction);
		}

		$related = $rootQuery->related($targetCollection);
		foreach ($relation->getInnerKeys() as $index => $innerKey) {
			$outerKey = $relation->getOuterKeys()[$index];
			$related->where(x()->eq(
				$related->field($outerKey),
				$parentSource->field($innerKey),
			));
		}

		$nested = $this->buildConditionsOnRelated($related, $targetCollection, $nestedFilter, $aliases, $scope, $context);
		if ($nested !== []) {
			$related->where(...$nested);
		}

		return x()->exists($related);
	}

	/**
	 * @return list<ConditionInterface>
	 */
	private function buildConditionsOnRelated(
		SelectQuery $related,
		CollectionInterface $collection,
		mixed $filters,
		array $aliases,
		string $scope,
		QueryContext $context,
	): array {
		if (! is_array($filters) || $filters === []) {
			return [];
		}

		$nodes = [];
		foreach ($filters as $key => $value) {
			if (
				is_string($key)
				&& str_contains($key, '.')
				&& $this->relationName($collection, $key, $aliases, $scope) === null
			) {
				[$relationKey, $nestedKey] = explode('.', $key, 2);
				$value = [$nestedKey => $value];
				$key = $relationKey;
			}

			if ($key === '_and' || $key === '_or') {
				if (! is_array($value)) {
					continue;
				}
				$children = [];
				foreach ($value as $group) {
					$childConditions = $this->buildConditionsOnRelated($related, $collection, $group, $aliases, $scope, $context);
					if ($childConditions === []) {
						continue;
					}
					$children[] = count($childConditions) === 1
						? $childConditions[0]
						: x()->and(...$childConditions);
				}
				if ($children !== []) {
					$nodes[] = $key === '_and' ? x()->and(...$children) : x()->or(...$children);
				}

				continue;
			}

			if (! is_string($key) || ! is_array($value)) {
				continue;
			}

			$relationName = $this->relationName($collection, $key, $aliases, $scope);
			if ($relationName !== null && ! $this->isOperatorArray($value)) {
				$relation = $collection->relations->get($relationName);
				$exists = $this->buildRelationExists(
					$related,
					$collection,
					$relation,
					$value,
					$aliases,
					$this->joinScope($scope, $key),
					$context,
					null,
				);
				if ($exists !== null) {
					$nodes[] = $exists;
				}

				continue;
			}

			foreach ($value as $operator => $operand) {
				if (! is_string($operator)) {
					continue;
				}
				$condition = $this->buildOperatorCondition($related, $collection, $key, $operator, $operand, $context, null);
				if ($condition !== null) {
					$nodes[] = $condition;
				}
			}
		}

		return $nodes;
	}

	private function buildOperatorCondition(
		SelectQuery $query,
		CollectionInterface $collection,
		string $field,
		string $operator,
		mixed $operand,
		QueryContext $context,
		?RelationRef $relationSource,
	): ?ConditionInterface {
		$left = $relationSource !== null
			? $this->relationValueExpression($relationSource, $collection, $field, $context)
			: $this->valueExpression($query, $collection, $field, $context);
		$resolved = $this->parameters->resolveOperand($operand, $context);

		return match ($operator) {
			'_eq' => x()->eq($left, $resolved),
			'_neq' => x()->neq($left, $resolved),
			'_lt' => x()->lt($left, $resolved),
			'_lte' => x()->lte($left, $resolved),
			'_gt' => x()->gt($left, $resolved),
			'_gte' => x()->gte($left, $resolved),
			'_contains' => x()->contains($left, (string) $resolved),
			'_ncontains' => x()->notContains($left, (string) $resolved),
			'_starts_with' => x()->startsWith($left, (string) $resolved),
			'_ends_with' => x()->endsWith($left, (string) $resolved),
			'_in' => x()->in($left, $this->parameters->resolveList($operand, $context)),
			'_nin' => x()->notIn($left, $this->parameters->resolveList($operand, $context)),
			'_between' => $this->betweenCondition($left, $operand, false, $context),
			'_nbetween' => $this->betweenCondition($left, $operand, true, $context),
			'_null' => x()->isNull($left),
			'_nnull' => x()->isNotNull($left),
			'_empty' => x()->or(x()->isNull($left), x()->eq($left, '')),
			'_nempty' => x()->and(x()->isNotNull($left), x()->neq($left, '')),
			default => null,
		};
	}

	private function betweenCondition(
		ValueExpressionInterface $left,
		mixed $operand,
		bool $negated,
		QueryContext $context,
	): ?ConditionInterface {
		$values = $this->parameters->parseArrayValue($operand);
		if (count($values) !== 2) {
			return null;
		}

		$condition = x()->and(
			x()->gte($left, $this->parameters->resolveOperand($values[0], $context)),
			x()->lte($left, $this->parameters->resolveOperand($values[1], $context)),
		);

		return $negated ? x()->not($condition) : $condition;
	}

	private function applySearch(SelectQuery $query, CollectionInterface $collection, mixed $search): void
	{
		$condition = $this->searchConditions($query, $collection, $search);
		if ($condition !== null) {
			$query->where($condition);
		}
	}

	private function searchConditions(
		SelectQuery|RelationRef $source,
		CollectionInterface $collection,
		mixed $search,
	): ?ConditionInterface {
		if ($search === null || $search === '') {
			return null;
		}

		$term = (string) $search;
		$stringFields = $this->getStringFields($collection);
		if ($stringFields === []) {
			return null;
		}

		$conditions = [];
		foreach ($stringFields as $fieldName) {
			$conditions[] = x()->contains($source->field($fieldName), $term);
		}

		return count($conditions) === 1 ? $conditions[0] : x()->or(...$conditions);
	}

	private function applySort(
		SelectQuery $query,
		CollectionInterface $collection,
		mixed $sort,
		QueryContext $context,
	): void {
		$sorts = [];
		foreach ($this->parameters->parseArrayValue($sort) as $item) {
			$item = trim((string) $item);
			if ($item === '') {
				continue;
			}
			$desc = str_starts_with($item, '-');
			$field = $desc ? substr($item, 1) : $item;
			$expression = $this->valueExpression($query, $collection, $field, $context);
			$sorts[] = $desc ? $expression->desc() : $expression->asc();
		}

		if ($sorts !== []) {
			$query->orderBy(...$sorts);
		}
	}

	private function applyPagination(SelectQuery $query, array $input, QueryContext $context, string $prefix = ''): void
	{
		$limitKey = $prefix . 'limit';
		$offsetKey = $prefix . 'offset';
		$pageKey = $prefix . 'page';

		$hasPagination = isset($input[$limitKey]) || isset($input[$offsetKey]) || isset($input[$pageKey]);
		$limit = $hasPagination && isset($input[$limitKey])
			? (int) $input[$limitKey]
			: $context->defaultLimit;
		$limit = min(max(1, $limit), $context->maxLimit);
		$offset = isset($input[$offsetKey]) ? max(0, (int) $input[$offsetKey]) : 0;
		if (isset($input[$pageKey])) {
			$offset = (max(1, (int) $input[$pageKey]) - 1) * $limit;
		}

		$query->limit($limit);
		if ($offset > 0) {
			$query->offset($offset);
		}
	}

	private function requirePrimaryKeys(
		SelectQuery $query,
		CollectionInterface $collection,
		?RelationRef $relationRef,
	): void {
		if ($relationRef !== null) {
			return;
		}

		// Default * already exposes PKs publicly. Only require INTERNAL when an explicit
		// select() omitted them — and never retag an already-public PK (that would strip it).
		$hasExplicitSelect = $query->getSelections()->getByTag(SelectionTag::DEFAULT) === [];
		if (! $hasExplicitSelect) {
			return;
		}

		$publicKeys = [];
		foreach ($query->getSelections()->getByTag(SelectionTag::PUBLIC) as $selection) {
			$publicKeys[$selection->getSelectionKey()] = true;
		}

		foreach ($this->primaryKeyFields($collection) as $field) {
			$ref = $query->field($field->getName());
			if (isset($publicKeys[$ref->getSelectionKey()])) {
				continue;
			}
			$query->require($ref, SelectionTag::INTERNAL);
		}
	}

	/**
	 * @return list<\ON\Data\Definition\Field\FieldInterface>
	 */
	private function primaryKeyFields(CollectionInterface $collection): array
	{
		$primary = $collection->getPrimaryKeyFields();

		return is_array($primary) ? array_values($primary) : ($primary !== null ? [$primary] : []);
	}

	private function valueExpression(
		SelectQuery $query,
		CollectionInterface $collection,
		string $field,
		QueryContext $context,
	): ValueExpressionInterface {
		$parsed = $this->functions->parse($field);
		if ($parsed->isFunction()) {
			$column = $collection->fields->has($parsed->field)
				? $collection->fields->get($parsed->field)->getColumn()
				: $parsed->field;

			return x()->rawSql($this->dateFunctionSql($parsed->function, $column, $context->databaseType));
		}

		return $query->field($parsed->field);
	}

	private function relationValueExpression(
		RelationRef $rel,
		CollectionInterface $collection,
		string $field,
		QueryContext $context,
	): ValueExpressionInterface {
		$parsed = $this->functions->parse($field);
		if ($parsed->isFunction()) {
			$column = $collection->fields->has($parsed->field)
				? $collection->fields->get($parsed->field)->getColumn()
				: $parsed->field;

			return x()->rawSql($this->dateFunctionSql($parsed->function, $column, $context->databaseType));
		}

		return $rel->field($parsed->field);
	}

	private function aggregateExpression(
		SelectQuery $query,
		CollectionInterface $collection,
		string $function,
		string $field,
		QueryContext $context,
	): ValueExpressionInterface {
		$distinct = false;
		$normalized = $function;
		if (str_ends_with($function, 'Distinct')) {
			$distinct = true;
			$normalized = substr($function, 0, -8);
		}

		$operand = $field === '*'
			? $query->all()
			: $this->valueExpression($query, $collection, $field, $context);

		return match (strtolower($normalized)) {
			'count' => $distinct ? x()->countDistinct($operand) : x()->count($operand),
			'sum' => x()->sum($operand),
			'avg' => x()->avg($operand),
			'min' => x()->min($operand),
			'max' => x()->max($operand),
			default => throw new RestApiError("Unsupported aggregate function '{$function}'.", 'INVALID_AGGREGATE', $function, 400),
		};
	}

	private function dateFunctionSql(string $function, string $column, string $databaseType): string
	{
		$function = strtolower($function);
		if (! in_array($function, self::DATE_FUNCTIONS, true)) {
			throw RestApiError::invalidField($function);
		}

		$quoted = $column;

		return match (strtolower($databaseType)) {
			'sqlite' => match ($function) {
				'year' => "CAST(strftime('%Y', {$quoted}) AS INTEGER)",
				'month' => "CAST(strftime('%m', {$quoted}) AS INTEGER)",
				'day' => "CAST(strftime('%d', {$quoted}) AS INTEGER)",
				'hour' => "CAST(strftime('%H', {$quoted}) AS INTEGER)",
				'date' => "date({$quoted})",
			},
			'postgres', 'pgsql' => match ($function) {
				'year' => "EXTRACT(YEAR FROM {$quoted})",
				'month' => "EXTRACT(MONTH FROM {$quoted})",
				'day' => "EXTRACT(DAY FROM {$quoted})",
				'hour' => "EXTRACT(HOUR FROM {$quoted})",
				'date' => "DATE({$quoted})",
			},
			default => match ($function) {
				'year' => "YEAR({$quoted})",
				'month' => "MONTH({$quoted})",
				'day' => "DAY({$quoted})",
				'hour' => "HOUR({$quoted})",
				'date' => "DATE({$quoted})",
			},
		};
	}

	/**
	 * @return list<string>
	 */
	private function getStringFields(CollectionInterface $collection): array
	{
		$stringTypes = ['string', 'text', 'varchar', 'char', 'longtext', 'mediumtext', 'tinytext'];
		$fields = [];

		foreach ($collection->fields as $name => $field) {
			if ($field->isHidden() || $field->isPrimaryKey()) {
				continue;
			}

			if (method_exists($field, 'isSearchable') && $field->isSearchable() === false) {
				continue;
			}

			try {
				if (in_array(strtolower($field->getType()), $stringTypes, true)) {
					$fields[] = $name;
				}
			} catch (Throwable) {
			}
		}

		return $fields;
	}

	private function isOperatorArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		foreach (array_keys($value) as $key) {
			if (! is_string($key) || ! str_starts_with($key, '_')) {
				return false;
			}
		}

		return true;
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
