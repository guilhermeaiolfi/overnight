<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\RootNode;
use ON\ORM\Definition\Collection\CollectionInterface;

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
		private array $relationFields,
		private array $deep,
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
		$configured = $this->configureRelations($root, $this->collection, $this->relationFields, $this->deep);

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

	private function configureRelations(AbstractNode $parent, CollectionInterface $collection, array $relations, array $deep): array
	{
		$configured = [];
		foreach ($relations as $relationName => $relationData) {
			if (!is_array($relationData)) {
				continue;
			}

			$relation = RelationLoad::create($collection, $relationName, $relationData, $deep[$relationName] ?? [], $this->context);
			if ($relation === null) {
				continue;
			}

			$relation->configure($parent, $this->factory);
			$relation->prepare();
			$relation->setChildren(
				$this->configureRelations(
					$relation->node(),
					$relation->targetCollection,
					$relation->getNestedRelations(),
					$relation->deep
				)
			);
			$configured[] = $relation;
		}

		return $configured;
	}

	/**
	 * @param list<RelationLoad> $relations
	 */
	private function loadRelations(array $relations): void
	{
		foreach ($relations as $relation) {
			$relation->load();
			$this->loadRelations($relation->children());
		}
	}

	private function cleanRows(CollectionInterface $collection, array $rows, array $requestedColumns, array $internalColumns, array $configured): array
	{
		$visible = array_flip($this->getVisibleFields($collection));
		$relationKeys = array_flip(array_map(fn(RelationLoad $relation) => $relation->responseName, $configured));
		foreach ($rows as &$row) {
			$row = array_intersect_key($row, $visible + $relationKeys);
			$row = $this->stripInternalColumns($row, $internalColumns, $requestedColumns);

			foreach ($configured as $relation) {
				$name = $relation->responseName;
				$value = $row[$name] ?? null;
				if ($value === null) {
					continue;
				}

				$row[$name] = $relation->isSingle()
					? $this->cleanRelationRow($relation, $value)
					: array_map(fn(array $item) => $this->cleanRelationRow($relation, $item), $value);
			}
		}
		unset($row);

		return $rows;
	}

	private function cleanRelationRow(RelationLoad $relation, array $row): array
	{
		$visible = array_flip($this->getVisibleFields($relation->targetCollection));
		$nestedRelationKeys = array_flip(array_map(fn(RelationLoad $child) => $child->responseName, $relation->children()));
		$syntheticKeys = array_flip(array_filter(array_keys($row), fn(string $key) => str_starts_with($key, '__on_')));
		$row = array_intersect_key($row, $visible + $nestedRelationKeys + $syntheticKeys);
		$row = $this->stripInternalColumns($row, $relation->getInternalColumns(), $relation->getRequestedColumns());
		foreach (array_keys($row) as $key) {
			if (str_starts_with((string) $key, '__on_')) {
				unset($row[$key]);
			}
		}

		foreach ($relation->children() as $child) {
			$value = $row[$child->responseName] ?? null;
			if ($value === null) {
				continue;
			}

			$row[$child->responseName] = $child->isSingle()
				? $this->cleanRelationRow($child, $value)
				: array_map(fn(array $item) => $this->cleanRelationRow($child, $item), $value);
		}

		return $row;
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

}
