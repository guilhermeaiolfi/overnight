<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\PaginationSpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SortDirection;
use ON\RestApi\Query\Node\SortSpec;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\Support\RegistrySupportTrait;

abstract class AbstractRelationHandler extends AbstractHandler
{
	use RegistrySupportTrait;

	/** @var array{select: array, requested: array, internal: array}|null */
	private ?array $columns = null;

	private CollectionInterface $targetCollection;

	public function __construct(
		protected CollectionInterface $collection,
		protected RelationInterface $relation,
		protected ItemRepositoryInterface $items,
		protected SqlQuerySpecCompiler $querySpecCompiler,
		protected ?RelationSelection $selection = null,
		protected ?AliasRegistry $aliases = null
	) {
		parent::__construct($collection, $selection?->responseName ?? $relation->getName(), $relation->getName());
		$this->targetCollection = $this->relation->getCollection();
	}

	public function getTargetCollection(): CollectionInterface
	{
		return $this->targetCollection;
	}

	public function isSingle(): bool
	{
		return $this->relation->getCardinality()->isSingle();
	}

	public function getSelectColumns(): array
	{
		return $this->selectionColumns()['select'];
	}

	public function getRequestedColumns(): array
	{
		return $this->selectionColumns()['requested'];
	}

	public function getInternalColumns(): array
	{
		return $this->selectionColumns()['internal'];
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

	protected function parseRows(AbstractNode $node, iterable $rows): void
	{
		foreach ($rows as $row) {
			$node->parseRow(0, $row);
		}
	}

	/**
	 * @return list<array<int|string, mixed>>
	 */
	protected function referenceValueSets(AbstractNode $node): array
	{
		$sets = [];
		foreach ($node->getReferenceValues() as $set) {
			$sets[] = is_array($set) ? $set : [$set];
		}

		return $sets;
	}

	protected function selectionPagination(): ?PaginationSpec
	{
		return $this->selection?->query->pagination;
	}

	/**
	 * @return list<string>
	 */
	protected function selectionWindowOrderBy(?string $tableAlias = null): array
	{
		if ($this->selection === null) {
			return [];
		}

		$table = $tableAlias ?? $this->targetCollection->getTable();
		$orders = [];
		foreach ($this->selection->query->sort as $sort) {
			if (
				! $sort instanceof SortSpec
				|| ! $sort->expression instanceof FieldExpression
				|| ! $this->targetCollection->fields->has($sort->expression->field)
			) {
				continue;
			}

			$column = $this->targetCollection->fields->get($sort->expression->field)->getColumn();
			$direction = $sort->direction === SortDirection::Desc ? 'DESC' : 'ASC';
			$orders[] = $table . '.' . $column . ' ' . $direction;
		}

		return $orders;
	}

	/**
	 * @return array{select: array, requested: array, internal: array}
	 */
	private function selectionColumns(): array
	{
		return $this->columns ??= $this->buildSelectionColumns();
	}

	/**
	 * @return array{select: array, requested: array, internal: array}
	 */
	private function buildSelectionColumns(): array
	{
		$fieldNames = $this->selectedFieldNames();
		$requestedFieldNames = $this->requestedFieldNames();
		$requiredKeys = $this->fieldNamesToColumnNames($this->targetCollection, $this->relation->getOuterKeys());

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

			$internal = $requiredKeys;
			foreach ($this->getRelationKeyColumnNames($this->targetCollection, $this->getNestedRelations()) as $nestedKey) {
				if (! in_array($nestedKey, $internal, true)) {
					$internal[] = $nestedKey;
				}
			}
			foreach ($internal as $column) {
				if (! in_array($column, $selected, true)) {
					$selected[] = $column;
				}
			}

			return [
				'select' => array_values(array_unique($selected)),
				'requested' => array_values(array_unique($requested)),
				'internal' => array_values(array_unique($internal)),
			];
		}

		$visible = $this->targetCollection->getVisibleColumns();
		$selected = $visible;
		foreach ($requiredKeys as $requiredKey) {
			if (! in_array($requiredKey, $selected, true)) {
				$selected[] = $requiredKey;
			}
		}

		return [
			'select' => array_values(array_unique($selected)),
			'requested' => $visible,
			'internal' => array_values(array_unique($requiredKeys)),
		];
	}

	private function selectedFieldNames(): array
	{
		if (
			$this->selection === null
			|| ! $this->selection->query->selection->explicit
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
			|| ! $this->selection->query->selection->explicit
			|| $this->hasWildcardSelection()
		) {
			return [];
		}

		$fields = [];
		foreach ($this->selection->query->selection->nodes as $node) {
			if ($node instanceof FieldSelection && ! $node->internal) {
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
