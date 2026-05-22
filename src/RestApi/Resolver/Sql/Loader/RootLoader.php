<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\RootNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\MutationTaskInterface;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\LiteralValue;
use ON\RestApi\Query\Node\RelationSelection;

final class RootLoader
{
	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string> $columns
	 * @param array<int, string> $requestedColumns
	 * @param array<int, string> $internalColumns
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $rows,
		private array $columns,
		private array $requestedColumns,
		private array $internalColumns,
		private array $relations,
		private QueryContext $context,
		private LoaderFactory $factory
	) {
	}

	public function load(): array
	{
		if ($this->rows === []) {
			return [];
		}

		$root = new RootNode($this->columns, [$this->getPrimaryKeyColumn($this->collection)]);
		$configured = $this->configureRelations($root, $this->collection, $this->relations);

		foreach ($this->rows as $row) {
			$root->parseRow(0, $this->numericRow($row, $this->columns));
		}

		$this->loadRelations($configured);

		return $this->cleanRows(
			$this->collection,
			$root->getResult(),
			$this->requestedColumns,
			$this->internalColumns,
			$configured
		);
	}

	public static function compileRootAction(array $node, MutationQueue $queue): MutationTaskInterface|MutationDeleteTaskInterface|null
	{
		$operation = $node['operation'];
		$collection = $node['collection'];
		$state = $node['state'];

		if ($operation === 'create') {
			return $queue->queueInsert($state);
		}

		if ($operation === 'update') {
			return $queue->queueUpdate(
				$collection,
				self::primaryKeyCriteria($collection, $state->getValue(self::getPrimaryKeyName($collection))),
				$state
			);
		}

		if ($operation === 'delete') {
			return $queue->queueDelete(
				$collection,
				self::primaryKeyCriteria($collection, $state->getValue(self::getPrimaryKeyName($collection)))
			);
		}

		return null;
	}

	private function configureRelations(AbstractNode $parent, CollectionInterface $collection, array $relations): array
	{
		$configured = [];
		foreach ($relations as $relationSelection) {
			if (!$relationSelection instanceof RelationSelection) {
				continue;
			}

			$loader = $this->factory->relation(
				$collection,
				$relationSelection,
				$this->context
			);
			if ($loader === null) {
				continue;
			}

			$loader->configureNode($parent);
			$loader->prepare();
			$loader->setChildren(
				$this->configureRelations(
					$loader->getNode(),
					$loader->getTargetCollection(),
					$loader->getNestedRelations()
				)
			);
			$configured[] = $loader;
		}

		return $configured;
	}

	/**
	 * @param list<RelationLoaderInterface> $relations
	 */
	private function loadRelations(array $relations): void
	{
		foreach ($relations as $relation) {
			$relation->load();
			$this->loadRelations($relation->getChildren());
		}
	}

	private function cleanRows(CollectionInterface $collection, array $rows, array $requestedColumns, array $internalColumns, array $configured): array
	{
		$visible = array_flip($this->getVisibleFields($collection));
		$requested = array_intersect_key(array_flip($requestedColumns), $visible);
		$relationKeys = array_flip(array_map(fn(RelationLoaderInterface $relation) => $relation->getResponseName(), $configured));
		foreach ($rows as &$row) {
			$row = array_intersect_key($row, $requested + $relationKeys);
			$row = $this->stripInternalColumns($row, $internalColumns, $requestedColumns);

			foreach ($configured as $relation) {
				$name = $relation->getResponseName();
				$value = $row[$name] ?? null;
				if ($value === null) {
					continue;
				}

				$row[$name] = $relation->isSingle()
					? $this->cleanRelationRow($relation, $value)
					: array_map(fn(array $item) => $this->cleanRelationRow($relation, $item), $value);
			}

			$row = $this->mapColumnsToFields($collection, $row);
		}
		unset($row);

		return $rows;
	}

	private function cleanRelationRow(RelationLoaderInterface $relation, array $row): array
	{
		$visible = array_flip($this->getVisibleFields($relation->getTargetCollection()));
		$nestedRelationKeys = array_flip(array_map(fn(RelationLoaderInterface $child) => $child->getResponseName(), $relation->getChildren()));
		$syntheticKeys = array_flip(array_filter(array_keys($row), fn(string $key) => str_starts_with($key, '__on_')));
		$row = array_intersect_key($row, $visible + $nestedRelationKeys + $syntheticKeys);
		$row = $this->stripInternalColumns($row, $relation->getInternalColumns(), $relation->getRequestedColumns());
		foreach (array_keys($row) as $key) {
			if (str_starts_with((string) $key, '__on_')) {
				unset($row[$key]);
			}
		}

		foreach ($relation->getChildren() as $child) {
			$value = $row[$child->getResponseName()] ?? null;
			if ($value === null) {
				continue;
			}

			$row[$child->getResponseName()] = $child->isSingle()
				? $this->cleanRelationRow($child, $value)
				: array_map(fn(array $item) => $this->cleanRelationRow($child, $item), $value);
		}

		return $this->mapColumnsToFields($relation->getTargetCollection(), $row);
	}

	private function numericRow(array $row, array $columns): array
	{
		$values = [];
		foreach ($columns as $column) {
			$values[] = $row[$column] ?? null;
		}

		return $values;
	}

	private function stripInternalColumns(array $row, array $internalColumns, array $requestedColumns): array
	{
		foreach ($internalColumns as $column) {
			if (!in_array($column, $requestedColumns, true)) {
				unset($row[$column]);
			}
		}

		return $row;
	}

	private function getVisibleFields(CollectionInterface $collection): array
	{
		$visible = [];
		foreach ($collection->fields as $field) {
			if (!$field->isHidden()) {
				$visible[] = $field->getColumn();
			}
		}

		return $visible;
	}

	private function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$primary = $collection->getPrimaryKey();
		if (is_array($primary)) {
			$primary = reset($primary);
		}

		return $primary->getColumn();
	}

	private function mapColumnsToFields(CollectionInterface $collection, array $row): array
	{
		$mapped = [];
		foreach ($row as $key => $value) {
			$name = $collection->fields->hasColumn((string) $key)
				? $collection->fields->getKeyByColumnName((string) $key)
				: $key;

			$mapped[$name] = $value;
		}

		return $mapped;
	}

	private static function primaryKeyCriteria(CollectionInterface $collection, mixed $id): ComparisonFilter
	{
		return new ComparisonFilter(
			new FieldExpression(self::getPrimaryKeyName($collection)),
			ComparisonOperator::Eq,
			new LiteralValue($id)
		);
	}

	private static function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getName();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getName();
		}

		return 'id';
	}

}
