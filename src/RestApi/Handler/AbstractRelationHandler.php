<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\SubQuery;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\SelectQuery;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\PaginationSpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\PrimaryKeyCriteria;

abstract class AbstractRelationHandler extends AbstractHandler implements MutationHandlerInterface
{
	/** @var array{select: array, requested: array, internal: array} */
	private array $columns;

	private CollectionInterface $targetCollection;

	public function __construct(
		protected CollectionInterface $collection,
		protected RelationInterface $relation,
		protected ?RelationSelection $selection = null,
		protected ?SqlDataSource $dataSource = null,
		protected ?SqlQuerySpecCompiler $querySpecCompiler = null,
		protected ?AliasRegistry $aliases = null
	) {
		parent::__construct($collection, $selection?->responseName ?? $relation->getName(), $relation->getName());
		$this->targetCollection = $this->relation->getCollection();
		$this->columns = $this->buildColumns();
	}

	public function getTargetCollection(): CollectionInterface
	{
		return $this->targetCollection;
	}

	public function isSingle(): bool
	{
		return $this->relation->getCardinality() === 'single';
	}

	public function getSelectColumns(): array
	{
		return $this->columns['select'];
	}

	public function getRequestedColumns(): array
	{
		return $this->columns['requested'];
	}

	public function getInternalColumns(): array
	{
		return $this->columns['internal'];
	}

	public function getNestedRelations(): array
	{
		if ($this->selection === null) {
			return [];
		}

		$relations = [];
		foreach ($this->selection->query->selection->nodes as $node) {
			if ($node instanceof RelationSelection) {
				$relations[] = $node;
			}
		}

		return $relations;
	}

	public function orderBy(): array
	{
		if ($this->selection === null) {
			return [];
		}

		return $this->querySpecCompiler->compileOrderBy(
			$this->targetCollection,
			$this->selection->query->sort,
			$this->orderByTableAlias()
		);
	}

	public function limit(): ?int
	{
		return $this->getPagination()?->limit;
	}

	public function offset(): ?int
	{
		return $this->getPagination()?->offset;
	}

	protected function baseQuery(array $columns): SelectQuery
	{
		return $this->dataSource->getDatabase()->select($columns)
			->from($this->targetCollection->getTable());
	}

	protected function applyRelationQueryOptions(SelectQuery $query): void
	{
		if ($this->selection === null) {
			return;
		}

		$this->querySpecCompiler->applyFilters(
			$query,
			$this->targetCollection,
			$this->selection->query->filter,
			null,
			$this->aliases
		);
		$this->querySpecCompiler->applySearch(
			$query,
			$this->targetCollection,
			$this->selection->query->search
		);
		$this->querySpecCompiler->applyOrderBy(
			$query,
			$this->targetCollection,
			$this->selection->query->sort
		);
	}

	protected function queryRows(SelectQuery $query, AbstractNode $node): void
	{
		foreach ($query->fetchAll(CycleStatementInterface::FETCH_NUM) as $row) {
			$node->parseRow(0, $row);
		}
	}

	protected function referenceValues(AbstractNode $node): array
	{
		return $node->getReferenceValues();
	}

	protected function flattenedReferenceValues(AbstractNode $node): array
	{
		$values = [];
		foreach ($this->referenceValues($node) as $set) {
			$value = is_array($set) ? reset($set) : $set;
			if ($value !== null && !in_array($value, $values, true)) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * @return list<array<int|string, mixed>>
	 */
	protected function getReferenceValueSets(AbstractNode $node): array
	{
		$sets = [];
		foreach ($this->referenceValues($node) as $set) {
			$sets[] = is_array($set) ? $set : [$set];
		}

		return $sets;
	}

	protected function limitedSubquery(SelectQuery $inner, array $columns, string $partitionColumn): SelectQuery
	{
		return $this->limitedSubqueryWithColumns($inner, $columns, $columns, $partitionColumn);
	}

	protected function limitedSubqueryWithColumns(
		SelectQuery $inner,
		array $innerColumns,
		array $outerColumns,
		string $partitionColumn
	): SelectQuery {
		$rowNumberAlias = '__on_row_number';
		$orderSql = $this->windowOrderSql();
		$rowNumberSql = 'ROW_NUMBER() OVER (PARTITION BY '
			. $this->compile(new Expression($partitionColumn))
			. $orderSql
			. ') AS '
			. $this->identifier($rowNumberAlias);

		$innerColumns[] = new Fragment($rowNumberSql);
		$inner->columns($innerColumns);

		$alias = '__on_limited_relation';
		$outer = $this->dataSource->getDatabase()->select($outerColumns)
			->from(new SubQuery($inner, $alias));

		$offset = $this->offset() ?? 0;
		if ($offset > 0) {
			$outer->where(new Expression($alias . '.' . $rowNumberAlias), '>', $offset);
		}

		if ($this->limit() !== null) {
			$outer->where(new Expression($alias . '.' . $rowNumberAlias), '<=', $offset + $this->limit());
		}

		return $outer;
	}

	protected function windowOrderSql(): string
	{
		$parts = [];
		foreach ($this->orderBy() as $order) {
			if (
				!is_array($order)
				|| !isset($order['expression'])
				|| !$order['expression'] instanceof FragmentInterface
			) {
				continue;
			}

			$direction = strtoupper((string) ($order['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
			$parts[] = $this->compile($order['expression']) . ' ' . $direction;
		}

		return $parts === [] ? '' : ' ORDER BY ' . implode(', ', $parts);
	}

	protected function orderByTableAlias(): ?string
	{
		return null;
	}

	protected function parseLoadedRows(AbstractNode $node, SelectQuery $query): void
	{
		$this->queryRows($query, $node);
	}

	protected function getVisibleFields(CollectionInterface $collection): array
	{
		$visible = [];
		foreach ($collection->fields as $field) {
			if (!$field->isHidden()) {
				$visible[] = $field->getColumn();
			}
		}

		return $visible;
	}

	protected function visibleFieldNames(CollectionInterface $collection): array
	{
		$visible = [];
		foreach ($collection->fields as $fieldName => $field) {
			if (!$field->isHidden()) {
				$visible[] = (string) $fieldName;
			}
		}

		return $visible;
	}

	protected function compile(FragmentInterface $expression): string
	{
		return $this->dataSource->getDatabase()->getDriver()->getQueryCompiler()->compile(
			new QueryParameters(),
			$this->dataSource->getDatabase()->getPrefix(),
			$expression
		);
	}

	protected function identifier(string $identifier): string
	{
		return $this->dataSource->getDatabase()->getDriver()->getQueryCompiler()->quoteIdentifier($identifier);
	}

	protected function mapRowToFieldNames(CollectionInterface $collection, array $row): array
	{
		$item = [];
		foreach ($row as $column => $value) {
			$name = $collection->fields->hasColumn((string) $column)
				? $collection->fields->getKeyByColumnName((string) $column)
				: (string) $column;

			$item[$name] = $value;
		}

		return $item;
	}

	public function mutationCollection(string $operation, mixed $item): CollectionInterface
	{
		return $this->getTargetCollection();
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		SqlDataSource $dataSource
	): array {
		return $this->getEmptyMutationPayload();
	}

	protected function queueChildMutations(array $children, MutationQueue $queue): void
	{
		foreach ($children['create'] ?? [] as $state) {
			if ($state instanceof MutationStateInterface) {
				$queue->queueInsert($state);
			}
		}

		foreach ($children['update'] ?? [] as $state) {
			if ($state instanceof MutationStateInterface) {
				$queue->queueUpdate($state->getCollection(), $this->primaryKeyCriteria($state), $state);
			}
		}

		foreach ($children['delete'] ?? [] as $state) {
			if ($state instanceof MutationStateInterface) {
				$queue->queueDelete($state->getCollection(), $this->primaryKeyCriteria($state));
			}
		}
	}

	protected function primaryKeyCriteria(MutationStateInterface $state): FilterNode
	{
		return PrimaryKeyCriteria::build($state->getCollection(), $this->getPrimaryKeyValueFromState($state));
	}

	public function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): ?PrimaryKeyValue
	{
		return $collection->getPrimaryKey()->extractFromInput($input);
	}

	protected function getPrimaryKeyColumns(CollectionInterface $collection): array
	{
		return $collection->getPrimaryKey()->getColumns();
	}

	protected function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	protected function normalizeRelationItems(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		return $this->isAssociativeArray($value) ? [$value] : $value;
	}

	protected function getEmptyMutationPayload(): array
	{
		return [
			'create' => [],
			'update' => [],
			'delete' => [],
			'connect' => [],
			'disconnect' => [],
		];
	}

	protected function hasOperationPayload(array $input): bool
	{
		foreach (['create', 'update', 'delete', 'connect', 'disconnect'] as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}

	protected function isDetailedPayload(mixed $input): bool
	{
		return is_array($input) && $this->isAssociativeArray($input) && $this->hasOperationPayload($input);
	}

	protected function normalizeDetailedPayload(array $input): array
	{
		$payload = $this->getEmptyMutationPayload();
		foreach (array_keys($payload) as $key) {
			$payload[$key] = $this->normalizeRelationItems($input[$key] ?? []);
		}

		return $payload;
	}

	protected function getCurrentRelationRows(SqlDataSource $dataSource, MutationStateInterface $source): array
	{
		$fieldValueMap = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$value = $source->getValue($innerKey);
			if ($value instanceof \ON\RestApi\Mutation\ValueRef && !$value->isReady()) {
				return [];
			}

			$fieldValueMap[$this->relation->outerKeys()[$index]] = $source->resolveValue($value);
		}

		return $this->fetchRowsByFields($dataSource, $this->getTargetCollection(), $fieldValueMap);
	}

	protected function getCurrentParentRow(SqlDataSource $dataSource, MutationStateInterface $source): ?array
	{
		$identity = $this->getPrimaryKeyValueFromState($source, false);
		if ($identity === null) {
			return null;
		}

		return $this->fetchRowByIdentity($dataSource, $source->getCollection(), $identity);
	}

	protected function fetchRowsByField(
		SqlDataSource $dataSource,
		CollectionInterface $collection,
		string $fieldName,
		mixed $value,
		?array $fieldNames = null
	): array {
		$fieldNames ??= $this->visibleFieldNames($collection);
		if (!in_array($fieldName, $fieldNames, true)) {
			$fieldNames[] = $fieldName;
		}

		$query = $dataSource->select($collection, $fieldNames);
		$query->where($collection->fields->get($fieldName)->getColumn(), $value);

		return array_map(
			fn(array $row): array => $this->mapRowToFieldNames($collection, $row),
			$dataSource->fetchAll($query)
		);
	}

	protected function fetchRowsByFields(
		SqlDataSource $dataSource,
		CollectionInterface $collection,
		array $fieldValueMap,
		?array $fieldNames = null
	): array {
		$fieldNames ??= $this->visibleFieldNames($collection);
		foreach (array_keys($fieldValueMap) as $fieldName) {
			if (!in_array((string) $fieldName, $fieldNames, true)) {
				$fieldNames[] = (string) $fieldName;
			}
		}

		$query = $dataSource->select($collection, $fieldNames);
		foreach ($fieldValueMap as $fieldName => $value) {
			$query->where($collection->fields->get((string) $fieldName)->getColumn(), $value);
		}

		return array_map(
			fn(array $row): array => $this->mapRowToFieldNames($collection, $row),
			$dataSource->fetchAll($query)
		);
	}

	protected function fetchRowByIdentity(
		SqlDataSource $dataSource,
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity
	): ?array
	{
		$fieldNames = $this->visibleFieldNames($collection);
		foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {
			if (!in_array($fieldName, $fieldNames, true)) {
				$fieldNames[] = $fieldName;
			}
		}

		$query = $dataSource->select($collection, $fieldNames);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $dataSource->fetchOne($query);

		return $row === null ? null : $this->mapRowToFieldNames($collection, $row);
	}

	protected function getPrimaryKeyValueFromState(
		MutationStateInterface $state,
		bool $requireReady = true
	): ?PrimaryKeyValue {
		$values = [];

		foreach ($state->getCollection()->getPrimaryKey()->getFieldNames() as $fieldName) {
			$value = $state->getValue($fieldName);
			if ($value instanceof \ON\RestApi\Mutation\ValueRef) {
				if (!$value->isReady() && $requireReady) {
					return null;
				}

				$values[$fieldName] = $value;
				continue;
			}

			if ($requireReady) {
				$value = $state->resolveValue($value);
			}

			if ($value === null && !$state->isValueReady($fieldName)) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return new PrimaryKeyValue($state->getCollection(), $values);
	}

	protected function applySourceValuesToTargetInput(array &$input, MutationStateInterface $source): void
	{
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$input[$this->relation->outerKeys()[$index]] = $source->getValue($innerKey);
		}
	}

	protected function getTargetIdentityFromSourceRow(array $row): ?PrimaryKeyValue
	{
		$values = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			if (!array_key_exists($innerKey, $row)) {
				return null;
			}

			$values[$this->relation->outerKeys()[$index]] = $row[$innerKey];
		}

		return new PrimaryKeyValue($this->getTargetCollection(), $values);
	}

	protected function setSourceRelationValuesFromTargetState(
		MutationStateInterface $source,
		MutationStateInterface $target
	): void {
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$source->setValue($innerKey, $target->getValue($this->relation->outerKeys()[$index]));
		}
	}

	/**
	 * @return array{select: array, requested: array, internal: array}
	 */
	private function buildColumns(): array
	{
		$fieldNames = $this->getSelectedFieldNames();
		$requestedFieldNames = $this->getRequestedFieldNames();
		$requiredKey = array_map(
			fn(string $fieldName): string => $this->targetCollection->fields->get($fieldName)->getColumn(),
			$this->relation->outerKeys()
		)[0];

		if ($fieldNames !== []) {
			$selected = [];
			foreach ($fieldNames as $fieldName) {
				if ($this->targetCollection->fields->has($fieldName)) {
					$selected[] = $this->targetCollection->fields->get($fieldName)->getColumn();
				}
			}

			$requested = [];
			foreach ($requestedFieldNames as $fieldName) {
				if ($this->targetCollection->fields->has($fieldName)) {
					$requested[] = $this->targetCollection->fields->get($fieldName)->getColumn();
				}
			}

			$internal = [$requiredKey];
			foreach ($this->getRelationKeyColumnNames($this->targetCollection, $this->getNestedRelations()) as $nestedKey) {
				if (!in_array($nestedKey, $internal, true)) {
					$internal[] = $nestedKey;
				}
			}
			foreach ($internal as $column) {
				if (!in_array($column, $selected, true)) {
					$selected[] = $column;
				}
			}

			return [
				'select' => array_values(array_unique($selected)),
				'requested' => array_values(array_unique($requested)),
				'internal' => array_values(array_unique($internal)),
			];
		}

		$visible = $this->getVisibleFields($this->targetCollection);
		$selected = $visible;
		if (!in_array($requiredKey, $selected, true)) {
			$selected[] = $requiredKey;
		}

		return [
			'select' => array_values(array_unique($selected)),
			'requested' => $visible,
			'internal' => [$requiredKey],
		];
	}

	private function getRelationKeyColumnNames(CollectionInterface $collection, array $relations): array
	{
		$columns = [];
		foreach ($relations as $relation) {
			if ($relation instanceof RelationSelection && $collection->relations->has($relation->relationName)) {
				foreach ($collection->relations->get($relation->relationName)->innerKeys() as $fieldName) {
					$columns[] = $collection->fields->get($fieldName)->getColumn();
				}
			}
		}

		return array_values(array_unique($columns));
	}

	private function getPagination(): ?PaginationSpec
	{
		return $this->selection?->query->pagination;
	}

	private function getSelectedFieldNames(): array
	{
		if (
			$this->selection === null
			|| !$this->selection->query->selection->explicit
			|| $this->hasWildcardSelection()
		) {
			return [];
		}

		$fields = [];
		foreach ($this->selection->query->selection->nodes as $node) {
			if ($node instanceof FieldSelection) {
				$fields[] = $node->field->field;
			}
		}

		return array_values(array_unique($fields));
	}

	private function getRequestedFieldNames(): array
	{
		if (
			$this->selection === null
			|| !$this->selection->query->selection->explicit
			|| $this->hasWildcardSelection()
		) {
			return [];
		}

		$fields = [];
		foreach ($this->selection->query->selection->nodes as $node) {
			if ($node instanceof FieldSelection && !$node->internal) {
				$fields[] = $node->field->field;
			}
		}

		return array_values(array_unique($fields));
	}

	private function hasWildcardSelection(): bool
	{
		if ($this->selection === null) {
			return false;
		}

		foreach ($this->selection->query->selection->nodes as $node) {
			if ($node instanceof WildcardSelection) {
				return true;
			}
		}

		return false;
	}
}
