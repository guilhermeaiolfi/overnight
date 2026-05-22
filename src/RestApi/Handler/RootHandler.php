<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

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

class RootHandler extends AbstractHandler
{
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
		if ($this->rows === []) {
			return [];
		}

		$this->parseRows();

		return $this->result();
	}

	public function rootNode(): RootNode
	{
		$node = $this->getNodeOrNull();
		if ($node instanceof RootNode) {
			return $node;
		}

		$node = new RootNode($this->columns, [$this->getPrimaryKeyColumn($this->getCollection())]);
		$this->setNode($node);

		return $node;
	}

	public function parseRows(): void
	{
		if ($this->rows === []) {
			return;
		}

		$root = $this->rootNode();
		foreach ($this->rows as $row) {
			$root->parseRow(0, $this->numericRow($row, $this->columns));
		}
	}

	public function result(): array
	{
		if ($this->rows === []) {
			return [];
		}

		return $this->cleanRows($this->getCollection(), $this->rootNode()->getResult(), $this);
	}

	public function mutationCollection(string $operation, mixed $item): CollectionInterface
	{
		return $this->getCollection();
	}

	public function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKey = self::getPrimaryKeyName($collection);

		return array_key_exists($primaryKey, $input) ? $input[$primaryKey] : null;
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		\ON\RestApi\Resolver\DataSourceInterface $dataSource
	): array {
		return [
			'create' => [],
			'update' => [],
			'delete' => [],
			'connect' => [],
			'disconnect' => [],
		];
	}

	public function compileRootAction(
		string $operation,
		MutationStateInterface $state,
		MutationQueue $queue
	): MutationTaskInterface|MutationDeleteTaskInterface|null {
		$collection = $state->getCollection();

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

	private function cleanRows(CollectionInterface $collection, array $rows, HandlerInterface $handler): array
	{
		$visible = array_flip($this->getVisibleFields($collection));
		$requested = array_intersect_key(array_flip($handler->getRequestedColumns()), $visible);
		$relationKeys = array_flip(array_map(fn(HandlerInterface $child) => $child->getResponseName(), $handler->getChildren()));

		foreach ($rows as &$row) {
			$row = array_intersect_key($row, $requested + $relationKeys);
			$row = $this->stripInternalColumns($row, $handler->getInternalColumns(), $handler->getRequestedColumns());

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

			$row = $this->mapColumnsToFields($collection, $row);
		}
		unset($row);

		return $rows;
	}

	private function cleanRelationRow(HandlerInterface $handler, array $row): array
	{
		$visible = array_flip($this->getVisibleFields($handler->getTargetCollection()));
		$nestedRelationKeys = array_flip(array_map(fn(HandlerInterface $child) => $child->getResponseName(), $handler->getChildren()));
		$syntheticKeys = array_flip(array_filter(array_keys($row), fn(string $key) => str_starts_with($key, '__on_')));
		$row = array_intersect_key($row, $visible + $nestedRelationKeys + $syntheticKeys);
		$row = $this->stripInternalColumns($row, $handler->getInternalColumns(), $handler->getRequestedColumns());

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

		return $this->mapColumnsToFields($handler->getTargetCollection(), $row);
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

	private function getNodeOrNull(): ?\Cycle\ORM\Parser\AbstractNode
	{
		try {
			return $this->getNode();
		} catch (\LogicException) {
			return null;
		}
	}
}
