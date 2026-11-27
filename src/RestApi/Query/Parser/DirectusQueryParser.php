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
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use function ON\Data\Query\x;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Query\Directus\DirectusFunctionParser;
use ON\RestApi\Query\Directus\DirectusParameterParser;
use ON\RestApi\Query\Directus\LiteralLike;
use ON\RestApi\Query\Directus\ParsedField;
use ON\RestApi\Query\QueryContext;
use Throwable;

final class DirectusQueryParser implements QueryParserInterface
{
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
		$isAggregateRequest = $aggregates !== [] || $groupFields !== [];
		if ($isAggregateRequest) {
			$this->applyAggregateQuery($query, $aggregates, $groupFields, $context);
		} else {
			$fieldPaths = $this->parameters->fieldPaths($parameters['fields'] ?? null);
			if ($fieldPaths !== null) {
				$this->applySelection($query, $fieldPaths, $aliases, $deep, '', $context);
			}
		}

		// Validate sort fields on aggregate requests while retaining the grouping order.
		$sorts = $this->sortExpressions($query, $parameters['sort'] ?? null, 'sort');
		if (! $isAggregateRequest && $sorts !== []) {
			$query->orderBy(...$sorts);
		}

		$this->applyFilter($query, $parameters['filter'] ?? null, $aliases, '', $context);
		$this->applySearch($query, $parameters['search'] ?? null);
		$this->applyPagination($query, ...$this->pagination($parameters, $context));
		$context->setMeta($this->parameters->parseArrayValue($parameters['meta'] ?? [], 'meta'));

		return $query;
	}

	private function applyAggregateQuery(
		SelectQuery $query,
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
			$parsed = $this->functions->parse($field);
			$alias = $parsed->alias($field);
			$expression = $this->valueExpression($query, $parsed);
			$selects[] = $expression->as($alias);
			$groupByMeta[] = ['responseName' => $field, 'alias' => $alias];
			$query->groupBy($expression);
			$query->orderBy($expression->asc());
		}

		foreach ($aggregates as $function => $fields) {
			foreach ($this->parameters->parseArrayValue($fields, 'aggregate') as $field) {
				$field = (string) $field;
				$alias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $function . '_' . $field);
				$selects[] = $this->aggregateExpression($query, (string) $function, $field)->as($alias);
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

	/**
	 * @param list<string> $fieldPaths
	 */
	private function applySelection(
		SelectQuery|RelationRef $source,
		array $fieldPaths,
		array $aliases,
		array $deep,
		string $scope,
		QueryContext $context,
	): void {
		$collection = $source->getCollection();
		$selectAll = in_array('*', $fieldPaths, true);
		$fields = [];
		$relations = [];
		foreach ($fieldPaths as $path) {
			if ($path === '' || $path === '*') {
				continue;
			}

			[$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
			if ($rest === null && $collection->hasField($first)) {
				$this->requireVisibleField($collection, $first);
				$fields[] = $first;

				continue;
			}

			if (! isset($relations[$first])) {
				$relation = $this->findRelation($collection, $first, $aliases, $scope)
					?? throw RestApiError::invalidField($first);
				$relations[$first] = ['definition' => $relation, 'paths' => []];
			}
			$relations[$first]['paths'][] = $rest ?? '*';
		}

		$fields = array_unique($fields);
		if ($source instanceof RelationRef) {
			if ($selectAll || $fields === []) {
				$source->load();
			} else {
				$source->select(...$fields);
			}
		} else {
			if (! $selectAll && $fields !== []) {
				$source->select(...array_map($source->field(...), $fields));
			} elseif (! $selectAll && $relations !== []) {
				$source->getSelections()->clear();
			} elseif ($fieldPaths === []) {
				// An explicit empty projection only exposes internal primary keys.
				$source->getSelections()->removeByTag(SelectionTag::DEFAULT);
			}
			$this->requirePrimaryKeys($source);
		}

		foreach ($relations as $responseName => ['definition' => $relation, 'paths' => $subPaths]) {
			$this->applyRelationSelection(
				$source,
				$relation,
				$responseName,
				$subPaths,
				$aliases,
				$deep,
				$scope,
				$context,
			);
		}
	}

	/**
	 * @param list<string> $subPaths
	 */
	private function applyRelationSelection(
		SelectQuery|RelationRef $source,
		RelationInterface $relation,
		string $responseName,
		array $subPaths,
		array $aliases,
		array $deep,
		string $scope,
		QueryContext $context,
	): void {
		$this->assertRelationDepth($scope, $context);
		$relationName = $relation->getName();

		if ($responseName !== $relationName) {
			$context->setRelationResponseName($this->joinScope($scope, $relationName), $responseName);
		}

		$rel = $source->relation($relationName);
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

		$this->applySelection($rel, $subPaths, $aliases, $relationDeep, $childScope, $context);
		$this->applyRelationDeep($rel, $relationDeep, $aliases, $childScope, $context);
	}

	private function applyRelationDeep(
		RelationRef $rel,
		array $deep,
		array $aliases,
		string $scope,
		QueryContext $context,
	): void {
		$this->applyFilter($rel, $deep['_filter'] ?? null, $aliases, $scope, $context);
		$this->applySearch($rel, $deep['_search'] ?? null);
		$sorts = $this->sortExpressions($rel, $deep['_sort'] ?? null, '_sort');
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

		if ($pagination === null && ! $definition->getCardinality()->isMany()) {
			return;
		}

		$pagination ??= $this->pagination([], $context, '_');
		if ($sorts === []) {
			$sorts = array_map(
				fn (FieldInterface $pk) => $rel->field($pk->getName())->asc(),
				$rel->getCollection()->getPrimaryKeyFields(),
			);
			if ($sorts === []) {
				throw DirectusParameterParser::invalidParameter($scope, 'requires a sort field for relation pagination');
			}
			$rel->orderBy(...$sorts);
		}

		$this->applyPagination($rel, ...$pagination);
	}

	private function applyFilter(
		SelectQuery|RelationRef $source,
		mixed $filters,
		array $aliases,
		string $scope,
		QueryContext $context,
		int $depth = 1,
	): void {
		$conditions = $this->buildConditions($source, $filters, $aliases, $scope, $context, $depth);
		if ($conditions !== []) {
			$source->where(...$conditions);
		}
	}

	/**
	 * @return list<ConditionInterface>
	 */
	private function buildConditions(
		SelectQuery|RelationRef $source,
		mixed $filters,
		array $aliases,
		string $scope,
		QueryContext $context,
		int $depth,
	): array {
		$filters = $this->parameters->objectValue($filters, 'filter');
		if ($filters === []) {
			return [];
		}
		if ($depth > $context->maxFilterDepth) {
			throw DirectusParameterParser::invalidParameter('filter', 'exceeds the configured nesting depth');
		}

		$collection = $source->getCollection();
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
					$childConditions = $this->buildConditions($source, $group, $aliases, $scope, $context, $depth + 1);
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

			$relation = $this->findRelation($collection, $key, $aliases, $scope);
			if ($relation === null && str_contains($key, '.')) {
				[$key, $nestedKey] = explode('.', $key, 2);
				$value = [$nestedKey => $value];
				$relation = $this->findRelation($collection, $key, $aliases, $scope);
			}

			if ($relation !== null && ! $this->isOperatorArray($value)) {
				$this->assertRelationDepth($scope, $context);
				if ($relation instanceof FirstOfManyRelation) {
					throw DirectusParameterParser::invalidParameter($key, 'does not support filtering by a first-of-many relation');
				}

				$nodes[] = $this->buildRelationExists(
					$source,
					$relation,
					$value,
					$aliases,
					$this->joinScope($scope, $key),
					$context,
					$depth + 1,
				);

				continue;
			}

			$left = $this->valueExpression($source, $key, filter: true);
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
		SelectQuery|RelationRef $source,
		RelationInterface $relation,
		array $nestedFilter,
		array $aliases,
		string $scope,
		QueryContext $context,
		int $depth,
	): ConditionInterface {
		$related = $source->getQuery()->relatedQuery(
			$source->relation($relation->getName()),
			function (SelectQuery $target) use ($nestedFilter, $aliases, $scope, $context, $depth): void {
				$this->applyFilter($target, $nestedFilter, $aliases, $scope, $context, $depth);
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

	private function applySearch(SelectQuery|RelationRef $source, mixed $search): void
	{
		if ($search === null || $search === '') {
			return;
		}

		$term = $this->parameters->scalarValue($search, 'search');
		$stringFields = $this->getStringFields($source->getCollection());
		if ($stringFields === []) {
			return;
		}

		$conditions = [];
		foreach ($stringFields as $fieldName) {
			$conditions[] = $this->textCondition($source->field($fieldName), $term, '_contains');
		}

		$source->where(count($conditions) === 1 ? $conditions[0] : x()->or(...$conditions));
	}

	/**
	 * @return list<Sort>
	 */
	private function sortExpressions(
		SelectQuery|RelationRef $source,
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
			$expression = $this->valueExpression($source, $field);
			$sorts[] = $desc ? $expression->desc() : $expression->asc();
		}

		return $sorts;
	}

	private function applyPagination(SelectQuery|RelationRef $source, int $limit, int $offset): void
	{
		$source->limit($limit);
		if ($offset > 0) {
			$source->offset($offset);
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

	private function requirePrimaryKeys(SelectQuery $query): void
	{
		// Default * already exposes PKs in public output. Only require non-explicit PKs
		// when an explicit select() omitted them.
		if ($query->getSelections()->getByTag(SelectionTag::DEFAULT) !== []) {
			return;
		}

		$visibleKeys = [];
		foreach ($query->getSelections()->getExplicit() as $selection) {
			$visibleKeys[$selection->getSelectionKey()] = true;
		}

		foreach ($query->getCollection()->getPrimaryKeyFields() as $field) {
			$ref = $query->field($field->getName());
			if (isset($visibleKeys[$ref->getSelectionKey()])) {
				continue;
			}
			$query->require($ref, 'internal');
		}
	}

	private function valueExpression(
		SelectQuery|RelationRef $source,
		string|ParsedField $field,
		bool $filter = false,
	): ValueExpressionInterface {
		$parsed = is_string($field) ? $this->functions->parse($field) : $field;
		$this->requireVisibleField($source->getCollection(), $parsed->field, $filter);
		$expression = $source->field($parsed->field);

		return $parsed->function === null
			? $expression
			: x()->fn()->call(DirectusFunctionParser::FUNCTION_CLASSES[$parsed->function], $expression);
	}

	private function requireVisibleField(CollectionInterface $collection, string $field, bool $filter = false): void
	{
		$definition = $collection->getField($field);
		if ($definition === null || $definition->isHidden() || ($filter && ! $definition->isFilterable())) {
			throw RestApiError::invalidField($field);
		}
	}

	private function aggregateExpression(
		SelectQuery $query,
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
			: $this->valueExpression($query, $field);

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

	private function findRelation(CollectionInterface $collection, string $responseName, array $aliases, string $scope): ?RelationInterface
	{
		$aliasKey = $this->joinScope($scope, $responseName);
		$relationName = $aliases[$aliasKey] ?? ($aliases[$responseName] ?? $responseName);

		$relation = $collection->getRelation($relationName);
		if ($relation?->getCollection()->isHidden()) {
			throw RestApiError::invalidField($aliasKey);
		}

		return $relation;
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
			$path = $this->joinScope($scope, $name);
			$relation = $this->findRelation($collection, $name, $aliases, $scope)
				?? throw RestApiError::invalidField($path);
			$this->validateDeepRelations(
				$relation->getCollection(),
				$this->parameters->objectValue($options, $path),
				$aliases,
				$path,
				$context,
			);
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
