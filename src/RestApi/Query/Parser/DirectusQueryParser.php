<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QueryFunction\Standard\Temporal\DateValue;
use ON\Data\Query\QueryFunction\Standard\Temporal\Day;
use ON\Data\Query\QueryFunction\Standard\Temporal\Hour;
use ON\Data\Query\QueryFunction\Standard\Temporal\Month;
use ON\Data\Query\QueryFunction\Standard\Temporal\Year;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use function ON\Data\Query\x;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Directus\DirectusFunctionParser;
use ON\RestApi\Query\Directus\DirectusParameterParser;
use ON\RestApi\Query\Directus\LiteralLike;
use ON\RestApi\Query\QueryContext;
use Throwable;

final class DirectusQueryParser implements QueryParserInterface
{
	/**
	 * @var array<string, class-string>
	 */
	private const FUNCTION_CLASSES = [
		'year' => Year::class,
		'month' => Month::class,
		'day' => Day::class,
		'hour' => Hour::class,
		'date' => DateValue::class,
	];

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

		$aliases = $this->parameters->normalizeAliases($parameters['alias'] ?? null);
		$deep = $this->parameters->objectValue($parameters['deep'] ?? null, 'deep');
		$this->validateDeepRelations($collection, $deep, $aliases, '', $context);

		$aggregates = $this->parameters->objectValue($parameters['aggregate'] ?? null, 'aggregate');
		$groupFields = $this->parameters->parseArrayValue($parameters['groupBy'] ?? null, 'groupBy');
		if ($aggregates !== [] || $groupFields !== []) {
			$this->applyAggregateQuery($query, $collection, $aggregates, $groupFields, $context);
			// Retain grouping order for aggregate requests while checking any requested sort fields.
			$this->sortExpressions($query, $collection, $parameters['sort'] ?? null, 'sort');
		} else {
			$this->applySelection($query, $collection, $parameters['fields'] ?? null, $aliases, $deep, '', $context);
			$this->applySort($query, $collection, $parameters['sort'] ?? null);
		}

		$this->applyFilter($query, $collection, $parameters['filter'] ?? [], $aliases, '', $context);
		$this->applySearch($query, $collection, $parameters['search'] ?? null);
		$this->applyPagination($query, $parameters, $context);
		$context->setMeta($this->parameters->parseArrayValue($parameters['meta'] ?? [], 'meta'));

		return $query;
	}

	private function applyAggregateQuery(
		SelectQuery $query,
		CollectionInterface $collection,
		array $aggregates,
		array $groupFields,
		QueryContext $context,
	): void {
		$aggregateMeta = [];
		$selects = [];

		$groupByMeta = [];
		foreach ($groupFields as $field) {
			$field = (string) $field;
			if ($field === '') {
				continue;
			}
			$alias = $this->functions->alias($field);
			$expression = $this->valueExpression($query, $collection, $field);
			$selects[] = $expression->as($alias);
			$groupByMeta[] = ['responseName' => $field, 'alias' => $alias];
			$query->groupBy($expression);
			$query->orderBy($expression->asc());
		}

		foreach ($aggregates as $function => $fields) {
			foreach ($this->parameters->parseArrayValue($fields, 'aggregate') as $field) {
				$field = (string) $field;
				$alias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $function . '_' . $field);
				$selects[] = $this->aggregateExpression($query, $collection, (string) $function, $field)->as($alias);
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

		$context->setAggregates($aggregateMeta);
		$context->setGroupBy($groupByMeta);
		$context->setAggregate($aggregateMeta !== [] || $groupByMeta !== []);
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
				$query->getSelections()->removeByTag(SelectionTag::DEFAULT);
				foreach ($pkRefs as $ref) {
					$query->require($ref, 'internal');
				}
			}

			return;
		}

		if ($fieldPaths === [] && $relationRef !== null) {
			return;
		}

		$selectAll = in_array('*', $fieldPaths, true);
		$rootFields = [];
		$relationPaths = [];
		foreach ($fieldPaths as $path) {
			if ($path === '' || $path === '*') {
				continue;
			}

			[$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
			if ($rest === null) {
				if ($collection->fields->has($first)) {
					$this->requireVisibleField($collection, $first);
					$rootFields[] = $first;

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
			if (! $selectAll && $rootFields !== []) {
				$expressions = array_map(fn (string $name) => $query->field($name), array_values(array_unique($rootFields)));
				$query->select(...$expressions);
			} elseif (! $selectAll && $relationPaths !== []) {
				$query->getSelections()->clear();
			}
			$this->requirePrimaryKeys($query, $collection, null);
		} else {
			if (! $selectAll && $rootFields !== []) {
				$relationRef->select(...array_values(array_unique($rootFields)));
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
		$this->assertRelationDepth($scope, $context);
		$relationName = $this->relationName($collection, $responseName, $aliases, $scope);
		if ($relationName === null) {
			throw RestApiError::invalidField($responseName);
		}

		$relation = $collection->relations->get($relationName);
		$targetCollection = $relation->getCollection();

		$relationPath = $this->joinScope($scope, $relationName);
		if ($responseName !== $relationName) {
			$context->setRelationResponseName($relationPath, $responseName);
		}

		$rel = $parentRelation === null
			? $query->relation($relationName)
			: $parentRelation->relation($relationName);

		$relationDeep = $deep[$responseName] ?? [];
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

		$searchConditions = $this->searchConditions($rel, $targetCollection, $deep['_search'] ?? null);
		if ($searchConditions !== null) {
			$rel->where($searchConditions);
		}

		$sorts = $this->sortExpressions($rel, $targetCollection, $deep['_sort'] ?? null, '_sort');
		$hasPagination = isset($deep['_limit']) || isset($deep['_offset']) || isset($deep['_page']);
		$pagination = $hasPagination ? $this->pagination($deep, $context, '_') : null;
		$definition = $rel->getDefinition();
		if ($definition instanceof FirstOfManyRelation) {
			// The loader picks exactly one row using the definition's order.
			return;
		}

		if ($sorts !== []) {
			$rel->orderBy(...$sorts);
		}

		// ON Data's junction loader does not apply per-parent selection pagination.
		if ($definition->isJunction() || ($pagination === null && ! $definition->getCardinality()->isMany())) {
			return;
		}

		[$limit, $offset] = $pagination ?? $this->pagination([], $context, '_');
		if ($sorts === []) {
			$sorts = array_map(
				fn (FieldInterface $pk) => $rel->field($pk->getName())->asc(),
				$targetCollection->getPrimaryKeyFields(),
			);
			if ($sorts === []) {
				throw DirectusParameterParser::invalidParameter($scope, 'requires a sort field for relation pagination');
			}
			$rel->orderBy(...$sorts);
		}

		$rel->limit($limit);
		if ($offset > 0) {
			$rel->offset($offset);
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
		int $depth = 1,
	): array {
		$filters = $this->parameters->objectValue($filters, 'filter');
		if ($filters === []) {
			return [];
		}
		if ($depth > $context->maxFilterDepth) {
			throw DirectusParameterParser::invalidParameter('filter', 'exceeds the configured nesting depth');
		}

		$nodes = [];
		foreach ($filters as $key => $value) {
			if (! is_string($key) || ! is_array($value)) {
				throw DirectusParameterParser::invalidParameter('filter', 'must map field names to filter objects');
			}
			if ($key === '_and' || $key === '_or') {
				$children = [];
				foreach ($value as $group) {
					if (! is_array($group)) {
						throw DirectusParameterParser::invalidParameter($key, 'must contain filter objects');
					}
					$childConditions = $this->buildConditions($rootQuery, $collection, $group, $aliases, $scope, $context, $relationSource, $depth + 1);
					if ($childConditions !== []) {
						$children[] = count($childConditions) === 1
							? $childConditions[0]
							: x()->and(...$childConditions);
					}
				}
				if ($children !== []) {
					$nodes[] = $key === '_and' ? x()->and(...$children) : x()->or(...$children);
				}

				continue;
			}

			if (str_contains($key, '.') && $this->relationName($collection, $key, $aliases, $scope) === null) {
				[$key, $nestedKey] = explode('.', $key, 2);
				$value = [$nestedKey => $value];
			}

			$relationName = $this->relationName($collection, $key, $aliases, $scope);
			if ($relationName !== null && ! $this->isOperatorArray($value)) {
				$this->assertRelationDepth($scope, $context);
				$relation = $collection->relations->get($relationName);
				if ($relation instanceof FirstOfManyRelation) {
					throw DirectusParameterParser::invalidParameter($key, 'does not support filtering by a first-of-many relation');
				}

				$nodes[] = $this->buildRelationExists(
					$rootQuery,
					$relation,
					$value,
					$aliases,
					$this->joinScope($scope, $key),
					$context,
					$relationSource,
					$depth + 1,
				);

				continue;
			}

			$left = $this->valueExpression($relationSource ?? $rootQuery, $collection, $key, filter: true);
			foreach ($value as $operator => $operand) {
				if (! is_string($operator)) {
					throw DirectusParameterParser::invalidParameter($key, 'must map filter operators to operands');
				}
				$nodes[] = $this->buildOperatorCondition($left, $operator, $operand, $context);
			}
		}

		return $nodes;
	}

	private function buildRelationExists(
		SelectQuery $rootQuery,
		RelationInterface $relation,
		array $nestedFilter,
		array $aliases,
		string $scope,
		QueryContext $context,
		?RelationRef $parentRelation,
		int $depth,
	): ConditionInterface {
		$targetCollection = $relation->getCollection();
		$query = $parentRelation?->getQuery() ?? $rootQuery;
		$relationRef = $parentRelation === null
			? $query->relation($relation->getName())
			: $parentRelation->relation($relation->getName());

		$related = $query->relatedQuery(
			$relationRef,
			function (SelectQuery $target) use ($targetCollection, $nestedFilter, $aliases, $scope, $context, $depth): void {
				$conditions = $this->buildConditions($target, $targetCollection, $nestedFilter, $aliases, $scope, $context, null, $depth);
				if ($conditions !== []) {
					$target->where(...$conditions);
				}
			},
		);

		return x()->exists($related);
	}

	private function buildOperatorCondition(
		ValueExpressionInterface $left,
		string $operator,
		mixed $operand,
		QueryContext $context,
	): ConditionInterface {
		if ($operator === '_in' || $operator === '_nin') {
			return $this->inCondition($left, $operand, $operator, $context);
		}
		if ($operator === '_between' || $operator === '_nbetween') {
			return $this->betweenCondition($left, $operand, $operator, $context);
		}

		$this->parameters->scalarValue($operand, $operator);
		$resolved = $this->parameters->resolveOperand($operand, $context);

		return match ($operator) {
			'_eq' => x()->eq($left, $resolved),
			'_neq' => x()->neq($left, $resolved),
			'_lt' => x()->lt($left, $this->orderedOperand($resolved, $operator)),
			'_lte' => x()->lte($left, $this->orderedOperand($resolved, $operator)),
			'_gt' => x()->gt($left, $this->orderedOperand($resolved, $operator)),
			'_gte' => x()->gte($left, $this->orderedOperand($resolved, $operator)),
			'_contains', '_ncontains', '_starts_with', '_ends_with' => $this->textCondition($left, $resolved, $operator),
			'_null' => x()->isNull($left),
			'_nnull' => x()->isNotNull($left),
			'_empty' => x()->or(x()->isNull($left), x()->eq($left, '')),
			'_nempty' => x()->and(x()->isNotNull($left), x()->neq($left, '')),
			default => throw DirectusParameterParser::invalidParameter($operator, 'is not a supported filter operator'),
		};
	}

	private function inCondition(
		ValueExpressionInterface $left,
		mixed $operand,
		string $operator,
		QueryContext $context,
	): ConditionInterface {
		$values = $this->parameters->resolveList($operand, $context, $operator);
		$negated = $operator === '_nin';
		if ($values === []) {
			// Preserve the truth value inside both AND and OR groups.
			return x()->eq(x()->literal(1), $negated ? 1 : 0);
		}

		return $negated ? x()->notIn($left, $values) : x()->in($left, $values);
	}

	private function betweenCondition(
		ValueExpressionInterface $left,
		mixed $operand,
		string $operator,
		QueryContext $context,
	): ConditionInterface {
		$values = $this->parameters->resolveList($operand, $context, $operator);
		if (count($values) !== 2) {
			throw DirectusParameterParser::invalidParameter($operator, 'requires exactly two bounds');
		}

		$condition = x()->and(
			x()->gte($left, $this->orderedOperand($values[0], $operator)),
			x()->lte($left, $this->orderedOperand($values[1], $operator)),
		);

		return $operator === '_nbetween' ? x()->not($condition) : $condition;
	}

	private function orderedOperand(mixed $value, string $operator): mixed
	{
		if ($value === null) {
			throw DirectusParameterParser::invalidParameter($operator, 'requires a non-null value');
		}

		return $value;
	}

	private function textCondition(ValueExpressionInterface $field, mixed $term, string $operator): ConditionInterface
	{
		$term = (string) $this->parameters->scalarValue($term, $operator);
		$escaped = strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']);
		$pattern = match ($operator) {
			'_starts_with' => $escaped . '%',
			'_ends_with' => '%' . $escaped,
			default => '%' . $escaped . '%',
		};

		return x()->eq(x()->fn()->call(LiteralLike::class, $field, $pattern, $operator === '_ncontains'), 1);
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

		$term = $this->parameters->scalarValue($search, 'search');
		$stringFields = $this->getStringFields($collection);
		if ($stringFields === []) {
			return null;
		}

		$conditions = [];
		foreach ($stringFields as $fieldName) {
			$conditions[] = $this->textCondition($source->field($fieldName), $term, '_contains');
		}

		return count($conditions) === 1 ? $conditions[0] : x()->or(...$conditions);
	}

	private function applySort(SelectQuery $query, CollectionInterface $collection, mixed $sort): void
	{
		$sorts = $this->sortExpressions($query, $collection, $sort, 'sort');
		if ($sorts !== []) {
			$query->orderBy(...$sorts);
		}
	}

	/**
	 * @return list<Sort>
	 */
	private function sortExpressions(
		SelectQuery|RelationRef $source,
		CollectionInterface $collection,
		mixed $sort,
		string $parameter,
	): array {
		$sorts = [];
		foreach ($this->parameters->parseArrayValue($sort, $parameter) as $item) {
			$item = trim((string) $item);
			if ($item === '') {
				continue;
			}
			$desc = str_starts_with($item, '-');
			$field = $desc ? substr($item, 1) : $item;
			$expression = $this->valueExpression($source, $collection, $field);
			$sorts[] = $desc ? $expression->desc() : $expression->asc();
		}

		return $sorts;
	}

	private function applyPagination(SelectQuery $query, array $input, QueryContext $context): void
	{
		[$limit, $offset] = $this->pagination($input, $context);
		$query->limit($limit);
		if ($offset > 0) {
			$query->offset($offset);
		}
	}

	/**
	 * @return array{int, int}
	 */
	private function pagination(array $input, QueryContext $context, string $prefix = ''): array
	{
		$limitKey = $prefix . 'limit';
		$offsetKey = $prefix . 'offset';
		$pageKey = $prefix . 'page';

		$limit = isset($input[$limitKey])
			? $this->parameters->integerValue($input[$limitKey], $limitKey)
			: $context->defaultLimit;
		$limit = min(max(1, $limit), max(1, $context->maxLimit));
		$offset = isset($input[$offsetKey])
			? max(0, $this->parameters->integerValue($input[$offsetKey], $offsetKey))
			: 0;

		if (isset($input[$pageKey])) {
			$page = max(1, $this->parameters->integerValue($input[$pageKey], $pageKey));
			if ($page > intdiv(PHP_INT_MAX, $limit)) {
				throw DirectusParameterParser::invalidParameter($pageKey, 'is too large');
			}
			$offset = ($page - 1) * $limit;
		}
		if ($offset > PHP_INT_MAX - $limit) {
			throw DirectusParameterParser::invalidParameter($offsetKey, 'is too large');
		}

		return [$limit, $offset];
	}

	private function requirePrimaryKeys(
		SelectQuery $query,
		CollectionInterface $collection,
		?RelationRef $relationRef,
	): void {
		if ($relationRef !== null) {
			return;
		}

		// Default * already exposes PKs in public output. Only require non-explicit PKs
		// when an explicit select() omitted them.
		$hasExplicitSelect = $query->getSelections()->getByTag(SelectionTag::DEFAULT) === [];
		if (! $hasExplicitSelect) {
			return;
		}

		$visibleKeys = [];
		foreach ($query->getSelections()->getExplicit() as $selection) {
			$visibleKeys[$selection->getSelectionKey()] = true;
		}

		foreach ($this->primaryKeyFields($collection) as $field) {
			$ref = $query->field($field->getName());
			if (isset($visibleKeys[$ref->getSelectionKey()])) {
				continue;
			}
			$query->require($ref, 'internal');
		}
	}

	/**
	 * @return list<FieldInterface>
	 */
	private function primaryKeyFields(CollectionInterface $collection): array
	{
		$primary = $collection->getPrimaryKeyFields();

		return is_array($primary) ? array_values($primary) : ($primary !== null ? [$primary] : []);
	}

	private function valueExpression(
		SelectQuery|RelationRef $source,
		CollectionInterface $collection,
		string $field,
		bool $filter = false,
	): ValueExpressionInterface {
		$parsed = $this->functions->parse($field);
		$this->requireVisibleField($collection, $parsed->field, $filter);
		$expression = $source->field($parsed->field);

		return $parsed->isFunction() ? $this->functionCall($parsed->function, $expression) : $expression;
	}

	private function requireVisibleField(CollectionInterface $collection, string $field, bool $filter = false): void
	{
		$definition = $collection->getField($field);
		if ($definition === null || $definition->isHidden() || ($filter && ! $definition->isFilterable())) {
			throw RestApiError::invalidField($field);
		}
	}

	private function functionCall(string $function, ValueExpressionInterface $expression): ValueExpressionInterface
	{
		$function = strtolower($function);
		$class = self::FUNCTION_CLASSES[$function] ?? null;
		if ($class === null) {
			throw RestApiError::invalidField($function);
		}

		return x()->fn()->call($class, $expression);
	}

	private function aggregateExpression(
		SelectQuery $query,
		CollectionInterface $collection,
		string $function,
		string $field,
	): ValueExpressionInterface {
		$distinct = false;
		$normalized = $function;
		if (str_ends_with($function, 'Distinct')) {
			$distinct = true;
			$normalized = substr($function, 0, -8);
		}

		$operand = $field === '*'
			? $query->all()
			: $this->valueExpression($query, $collection, $field);

		return match (strtolower($normalized)) {
			'count' => $distinct ? x()->countDistinct($operand) : x()->count($operand),
			'sum' => x()->sum($operand),
			'avg' => x()->avg($operand),
			'min' => x()->min($operand),
			'max' => x()->max($operand),
			default => throw new RestApiError("Unsupported aggregate function '{$function}'.", 'INVALID_AGGREGATE', $function, 400),
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
			if ($field->isHidden() || $field->isPrimaryKey() || ! $field->isFilterable()) {
				continue;
			}

			if ($field->isSearchable() === false) {
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

		$relation = $collection->getRelation($relationName);
		if ($relation === null) {
			return null;
		}
		if ($relation->getCollection()->isHidden()) {
			throw RestApiError::invalidField($aliasKey);
		}

		return $relationName;
	}

	private function validateDeepRelations(
		CollectionInterface $collection,
		array $deep,
		array $aliases,
		string $scope,
		QueryContext $context,
	): void {
		foreach ($deep as $name => $options) {
			if (! is_string($name)) {
				throw DirectusParameterParser::invalidParameter('deep', 'must map relation names to options');
			}
			if ($scope !== '' && str_starts_with($name, '_')) {
				continue;
			}

			$this->assertRelationDepth($scope, $context);
			$relation = $this->relationName($collection, $name, $aliases, $scope);
			$path = $this->joinScope($scope, $name);
			if ($relation === null) {
				throw RestApiError::invalidField($path);
			}

			$target = $collection->relations->get($relation)->getCollection();
			$this->validateDeepRelations($target, $this->parameters->objectValue($options, $path), $aliases, $path, $context);
		}
	}

	private function assertRelationDepth(string $scope, QueryContext $context): void
	{
		$depth = $scope === '' ? 1 : substr_count($scope, '.') + 2;
		if ($depth > $context->maxRelationDepth) {
			throw DirectusParameterParser::invalidParameter('relation', 'exceeds the configured nesting depth');
		}
	}

	private function joinScope(string $scope, string $name): string
	{
		return $scope === '' ? $name : $scope . '.' . $name;
	}
}
