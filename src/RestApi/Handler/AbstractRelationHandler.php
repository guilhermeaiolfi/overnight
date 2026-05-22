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
		return $this->pagination()?->limit;
	}

	public function offset(): ?int
	{
		return $this->pagination()?->offset;
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

	protected function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$primary = $collection->getPrimaryKey();
		if (is_array($primary)) {
			$primary = reset($primary);
		}

		return $primary->getColumn();
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
		return $this->emptyMutationPayload();
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

	protected function primaryKeyCriteria(MutationStateInterface $state): ComparisonFilter
	{
		$primaryKey = $this->getPrimaryKeyName($state->getCollection());

		return new ComparisonFilter(
			new FieldExpression($primaryKey),
			ComparisonOperator::Eq,
			new LiteralValue($state->getValue($primaryKey))
		);
	}

	public function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKey = $this->getPrimaryKeyName($collection);

		return array_key_exists($primaryKey, $input) ? $input[$primaryKey] : null;
	}

	protected function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$primary = $collection->getPrimaryKey();
		if (is_array($primary)) {
			$primary = reset($primary);
		}

		return $primary?->getName() ?? 'id';
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

	protected function emptyMutationPayload(): array
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
		$payload = $this->emptyMutationPayload();
		foreach (array_keys($payload) as $key) {
			$payload[$key] = $this->normalizeRelationItems($input[$key] ?? []);
		}

		return $payload;
	}

	protected function currentRelationRows(SqlDataSource $dataSource, MutationStateInterface $source): array
	{
		$parentId = $source->getValue($this->relation->getInnerField()->getName());
		if ($parentId instanceof \ON\RestApi\Mutation\ValueRef && !$parentId->isReady()) {
			return [];
		}

		return $this->fetchRowsByField(
			$dataSource,
			$this->getTargetCollection(),
			$this->relation->getOuterField()->getName(),
			$source->resolveValue($parentId)
		);
	}

	protected function currentParentRow(SqlDataSource $dataSource, MutationStateInterface $source): ?array
	{
		$primaryKey = $this->getPrimaryKeyName($source->getCollection());
		$id = $source->getValue($primaryKey);
		if ($id instanceof \ON\RestApi\Mutation\ValueRef && !$id->isReady()) {
			return null;
		}

		$id = $source->resolveValue($id);
		if ($id === null || $id === '') {
			return null;
		}

		return $this->fetchRowById($dataSource, $source->getCollection(), (string) $id);
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

	protected function fetchRowById(SqlDataSource $dataSource, CollectionInterface $collection, string $id): ?array
	{
		$fieldNames = $this->visibleFieldNames($collection);
		$primaryKey = $this->getPrimaryKeyName($collection);
		if (!in_array($primaryKey, $fieldNames, true)) {
			$fieldNames[] = $primaryKey;
		}

		$query = $dataSource->select($collection, $fieldNames);
		$query->where($this->getPrimaryKeyColumn($collection), $id)->limit(1);
		$row = $dataSource->fetchOne($query);

		return $row === null ? null : $this->mapRowToFieldNames($collection, $row);
	}

	/**
	 * @return array{select: array, requested: array, internal: array}
	 */
	private function buildColumns(): array
	{
		$fieldNames = $this->selectedFieldNames();
		$requestedFieldNames = $this->requestedFieldNames();
		$requiredKey = $this->relation->getOuterField()->getColumn();

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
			foreach ($this->relationKeyColumnNames($this->targetCollection, $this->getNestedRelations()) as $nestedKey) {
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

	private function relationKeyColumnNames(CollectionInterface $collection, array $relations): array
	{
		$columns = [];
		foreach ($relations as $relation) {
			if ($relation instanceof RelationSelection && $collection->relations->has($relation->relationName)) {
				$columns[] = $collection->relations->get($relation->relationName)->getInnerField()->getColumn();
			}
		}

		return array_values(array_unique($columns));
	}

	private function pagination(): ?PaginationSpec
	{
		return $this->selection?->query->pagination;
	}

	private function selectedFieldNames(): array
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

	private function requestedFieldNames(): array
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
