<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\RootNode;
use ON\ORM\Definition\Collection\CollectionInterface;

class RootHandler extends AbstractHandler
{
	private bool $assembled = false;

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string> $columns
	 * @param array<int, string> $requestedColumns
	 * @param array<int, string> $internalColumns
	 */
	public function __construct(
		CollectionInterface $collection,
		private array $rows = [],
		private array $columns = [],
		private array $requestedColumns = [],
		private array $internalColumns = []
	) {
		parent::__construct($collection, $collection->getName());
	}

	public function getTargetCollection(): CollectionInterface
	{
		return $this->getCollection();
	}

	public function isSingle(): bool
	{
		return false;
	}

	public function configureParserNode(\Cycle\ORM\Parser\AbstractNode $parent): \Cycle\ORM\Parser\AbstractNode
	{
		return $parent;
	}

	public function getSelectColumns(): array
	{
		return $this->columns;
	}

	public function getRequestedColumns(): array
	{
		return $this->requestedColumns;
	}

	public function getInternalColumns(): array
	{
		return $this->internalColumns;
	}

	public function getNestedRelations(): array
	{
		return [];
	}

	public function load(): array
	{
		return $this->fetchData();
	}

	public function rootNode(): RootNode
	{
		$node = $this->getNodeOrNull();
		if ($node instanceof RootNode) {
			return $node;
		}

		$node = new RootNode($this->columns, $this->getPrimaryKeyColumns($this->getCollection()));
		$this->setNode($node);

		return $node;
	}

	private function parseRows(): void
	{
		if ($this->rows === []) {
			return;
		}

		$root = $this->rootNode();
		foreach ($this->rows as $row) {
			$root->parseRow(0, $this->numericRow($row, $this->columns));
		}
	}

	public function fetchData(): array
	{
		if ($this->rows === []) {
			return [];
		}

		if (!$this->assembled) {
			$this->parseRows();
			$this->loadChildren($this);
			$this->assembled = true;
		}

		return $this->cleanRows($this->getCollection(), $this->rootNode()->getResult(), $this);
	}

	private function loadChildren(HandlerInterface $handler): void
	{
		foreach ($handler->getChildren() as $child) {
			$child->load();
			$this->loadChildren($child);
		}
	}

	private function cleanRows(CollectionInterface $collection, array $rows, HandlerInterface $handler): array
	{
		$visible = array_flip($collection->getVisibleColumns());
		$requested = array_intersect_key(array_flip($handler->getRequestedColumns()), $visible);
		$relationKeys = array_flip(array_map(fn(HandlerInterface $child) => $child->getResponseName(), $handler->getChildren()));
		$internalColumns = $this->internalColumnsExceptRelationKeys($handler->getInternalColumns(), $relationKeys);

		foreach ($rows as &$row) {
			$row = array_intersect_key($row, $requested + $relationKeys);
			$row = $this->stripInternalColumns($row, $internalColumns, $handler->getRequestedColumns());

			foreach ($handler->getChildren() as $child) {
				$name = $child->getResponseName();
				$value = $row[$name] ?? null;
				if ($value === null) {
					continue;
				}

				$row[$name] = $child->isSingle()
					? $this->cleanRelationRow($child, $value)
					: array_map(fn(array $item) => $this->cleanRelationRow($child, $item), $value);
			}

			$relationData = array_intersect_key($row, $relationKeys);
			$scalarRow = array_diff_key($row, $relationKeys);
			$scalarRow = $this->restoreCollidingForeignKeyScalars(
				$collection,
				$handler,
				$scalarRow,
				$relationData,
				$requested,
			);
			$row = $collection->mapRowFromColumns($scalarRow) + $relationData;
		}
		unset($row);

		return $rows;
	}

	private function cleanRelationRow(HandlerInterface $handler, array $row): array
	{
		$visible = array_flip($handler->getTargetCollection()->getVisibleColumns());
		$nestedRelationKeys = array_flip(array_map(fn(HandlerInterface $child) => $child->getResponseName(), $handler->getChildren()));
		$syntheticKeys = array_flip(array_filter(array_keys($row), fn(string $key) => str_starts_with($key, '__on_')));
		$row = array_intersect_key($row, $visible + $nestedRelationKeys + $syntheticKeys);
		$row = $this->stripInternalColumns(
			$row,
			$this->internalColumnsExceptRelationKeys($handler->getInternalColumns(), $nestedRelationKeys),
			$handler->getRequestedColumns(),
		);

		foreach (array_keys($row) as $key) {
			if (str_starts_with((string) $key, '__on_')) {
				unset($row[$key]);
			}
		}

		foreach ($handler->getChildren() as $child) {
			$value = $row[$child->getResponseName()] ?? null;
			if ($value === null) {
				continue;
			}

			$row[$child->getResponseName()] = $child->isSingle()
				? $this->cleanRelationRow($child, $value)
				: array_map(fn(array $item) => $this->cleanRelationRow($child, $item), $value);
		}

		$relationData = array_intersect_key($row, $nestedRelationKeys);
		$scalarRow = array_diff_key($row, $nestedRelationKeys);

		return $handler->getTargetCollection()->mapRowFromColumns($scalarRow) + $relationData;
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

	/**
	 * @param array<int, string> $internalColumns
	 * @param array<string, int> $relationKeys
	 * @return array<int, string>
	 */
	private function internalColumnsExceptRelationKeys(array $internalColumns, array $relationKeys): array
	{
		return array_values(array_filter(
			$internalColumns,
			static fn(string $column): bool => !isset($relationKeys[$column]),
		));
	}

	/**
	 * When a belongsTo response name matches its FK column, the loaded relation replaces
	 * the scalar value under the same key. Rehydrate explicitly requested FK scalars.
	 *
	 * @param array<string, int> $requested
	 * @param array<string, mixed> $scalarRow
	 * @param array<string, mixed> $relationData
	 * @return array<string, mixed>
	 */
	private function restoreCollidingForeignKeyScalars(
		CollectionInterface $collection,
		HandlerInterface $handler,
		array $scalarRow,
		array $relationData,
		array $requested,
	): array {
		foreach ($handler->getChildren() as $child) {
			if (!$child->isSingle()) {
				continue;
			}

			$relationName = $child->getRelationName();
			if ($relationName === null || !$collection->relations->has($relationName)) {
				continue;
			}

			$relation = $collection->relations->get($relationName);
			$responseName = $child->getResponseName();

			foreach ($relation->innerKeys() as $fieldName) {
				if (!$collection->fields->has($fieldName)) {
					continue;
				}

				$column = $collection->fields->get($fieldName)->getColumn();
				if ($column !== $responseName || !isset($requested[$column])) {
					continue;
				}

				$relationValue = $relationData[$responseName] ?? null;
				if (!is_array($relationValue)) {
					$scalarRow[$column] = null;
					continue;
				}

				$outerKey = $relation->outerKeys()[0] ?? null;
				$scalarRow[$column] = $outerKey !== null
					? ($relationValue[$outerKey] ?? null)
					: null;
			}
		}

		return $scalarRow;
	}

	private function getPrimaryKeyColumns(CollectionInterface $collection): array
	{
		return $collection->getPrimaryKey()->getColumns();
	}

	private function getNodeOrNull(): ?\Cycle\ORM\Parser\AbstractNode
	{
		try {
			return $this->getNode();
		} catch (\LogicException) {
			return null;
		}
	}
}
