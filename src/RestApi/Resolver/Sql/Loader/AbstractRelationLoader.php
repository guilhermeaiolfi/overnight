<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

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

abstract class AbstractRelationLoader implements RelationLoaderInterface
{
	/** @var array{select: array, requested: array, internal: array} */
	private array $columns;

	private ?AbstractNode $node = null;

	/** @var list<RelationLoaderInterface> */
	private array $children = [];

	private CollectionInterface $targetCollection;

	/**
	 * @param array{fields?: array, relations?: array, _relation?: string} $fieldTree
	 */
	public function __construct(
		protected RelationInterface $relation,
		protected string $responseName,
		protected array $fieldTree,
		protected array $deep,
		protected QueryContext $context
	) {
		$targetCollection = $this->context->registry->getCollection($this->relation->getCollection());
		if ($targetCollection === null) {
			throw new \LogicException("Target collection {$this->relation->getCollection()} is not registered.");
		}

		$this->targetCollection = $targetCollection;
		$this->columns = $this->buildColumns();
	}

	public function prepare(): void
	{
	}

	public function getNode(): AbstractNode
	{
		if ($this->node === null) {
			throw new \LogicException('Relation loader has not been configured.');
		}

		return $this->node;
	}

	protected function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	/**
	 * @return list<RelationLoaderInterface>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	/**
	 * @param list<RelationLoaderInterface> $children
	 */
	public function setChildren(array $children): void
	{
		$this->children = $children;
	}

	public function getResponseName(): string
	{
		return $this->responseName;
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

	public function getNestedRelations(?string $relationName = null): ?array
	{
		$relations = $this->fieldTree['relations'] ?? [];
		if ($relationName === null) {
			return $relations;
		}

		$relation = $relations[$relationName] ?? null;

		return is_array($relation) ? $relation : null;
	}

	public function getRequestedColumns(): array
	{
		return $this->columns['requested'];
	}

	public function getInternalColumns(): array
	{
		return $this->columns['internal'];
	}

	public function getDeep(): array
	{
		return $this->deep;
	}

	public function filters(): array
	{
		return !empty($this->deep['_filter']) && is_array($this->deep['_filter'])
			? $this->deep['_filter']
			: [];
	}

	public function orderBy(): array
	{
		if (empty($this->deep['_sort'])) {
			return [];
		}

		$orders = [];
		foreach (array_map('trim', explode(',', (string) $this->deep['_sort'])) as $part) {
			if ($part === '') {
				continue;
			}

			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}

			$expression = $this->context->expressions->value(
				$this->targetCollection,
				$part,
				$this->targetCollection->getTable()
			);
			if ($expression !== null) {
				$orders[] = [
					'expression' => $expression,
					'direction' => $direction,
				];
			}
		}

		return $orders;
	}

	public function limit(): ?int
	{
		return isset($this->deep['_limit']) ? (int) $this->deep['_limit'] : null;
	}

	public function offset(): ?int
	{
		return isset($this->deep['_offset']) ? (int) $this->deep['_offset'] : null;
	}

	protected function baseQuery(array $columns): SelectQuery
	{
		return $this->context->database->select($columns)
			->from($this->targetCollection->getTable());
	}

	protected function applyRelationQueryOptions(SelectQuery $query): void
	{
		if ($this->filters() !== []) {
			$this->context->filterApplier->apply($query, $this->targetCollection, $this->filters());
		}

		foreach ($this->orderBy() as $order) {
			if (
				!is_array($order)
				|| !isset($order['expression'])
				|| !$order['expression'] instanceof FragmentInterface
			) {
				continue;
			}

			$query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
		}
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
	): SelectQuery
	{
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
		$outer = $this->context->database->select($outerColumns)
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
		return $this->context->database->getDriver()->getQueryCompiler()->compile(
			new QueryParameters(),
			$this->context->database->getPrefix(),
			$expression
		);
	}

	protected function identifier(string $identifier): string
	{
		return $this->context->database->getDriver()->getQueryCompiler()->quoteIdentifier($identifier);
	}

	/**
	 * @return array{select: array, requested: array, internal: array}
	 */
	private function buildColumns(): array
	{
		$fieldNames = $this->fieldTree['fields'] ?? [];
		$requestedFieldNames = $this->fieldTree['requestedFields'] ?? $fieldNames;
		$requiredKey = (string) $this->relation->getOuterKey();

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
			foreach (
				$this->relationKeyColumnNames($this->targetCollection, $this->getNestedRelations() ?? [])
				as $nestedKey
			) {
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

		$visible = [];
		foreach ($this->targetCollection->fields as $field) {
			if (!$field->isHidden()) {
				$visible[] = $field->getColumn();
			}
		}

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
		foreach ($relations as $name => $data) {
			$targetName = is_array($data) ? ($data['_relation'] ?? $name) : $name;
			if ($collection->relations->has($targetName)) {
				$columns[] = (string) $collection->relations->get($targetName)->getInnerKey();
			}
		}

		return array_values(array_unique($columns));
	}
}
