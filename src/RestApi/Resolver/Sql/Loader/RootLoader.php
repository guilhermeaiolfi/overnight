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

			$loader = $this->factory->relation(
				$collection,
				$relationName,
				$relationData,
				is_array($deep[$relationName] ?? null) ? $deep[$relationName] : [],
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
					$loader->getNestedRelations() ?? [],
					$loader->getDeep()
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

}
