<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\Injection\Expression;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Handler\Mutation\ManyToManyApply;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;

class ManyToManyHandler extends AbstractRelationHandler implements RelationMutationHandlerInterface
{
	use ManyToManyApply;

	private ?string $junctionAlias = null;
	private ?string $targetAlias = null;

	public function __construct(
		CollectionInterface $collection,
		protected M2MRelation $manyToMany,
		ItemRepositoryInterface $items,
		SqlQuerySpecCompiler $querySpecCompiler,
		?RelationSelection $selection = null,
		?AliasRegistry $aliases = null
	) {
		parent::__construct($collection, $manyToMany, $items, $querySpecCompiler, $selection, $aliases);
	}

	public function configureParserNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->resultNodeColumns(),
			$this->pivotPrimaryKeyColumns(),
			$this->throughInnerKeyColumns(),
			$this->relationInnerKeyColumns()
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): mixed
	{
		$node = $this->getNode();
		$parentKeySets = $this->getReferenceValueSets($node);
		if ($parentKeySets === []) {
			return null;
		}

		$through = $this->manyToMany->through;
		$junctionAlias = $this->junctionAlias();
		$targetAlias = $this->targetAlias();
		$throughInnerKeys = $this->throughInnerKeyColumns();
		$throughOuterKeys = $this->throughOuterKeyColumns();
		$targetKeyColumns = $this->targetOuterKeyColumns();

		$selectColumns = $this->selectColumns($targetAlias, $junctionAlias);
		$query = $this->items->getDatabase()->select($selectColumns)
			->from($through->getCollection()->getTable() . ' AS ' . $junctionAlias)
			->innerJoin($this->getTargetCollection()->getTable(), $targetAlias);
		foreach ($throughOuterKeys as $index => $throughOuterKey) {
			$method = $index === 0 ? 'on' : 'andOn';
			$query->{$method}($junctionAlias . '.' . $throughOuterKey, '=', $targetAlias . '.' . $targetKeyColumns[$index]);
		}
		if (count($throughInnerKeys) === 1) {
			$query->where($junctionAlias . '.' . $throughInnerKeys[0], 'IN', array_map(
				static fn(array $set): mixed => reset($set),
				$parentKeySets
			));
		} else {
			$query->where(function ($nested) use ($parentKeySets, $throughInnerKeys, $junctionAlias) {
				foreach ($parentKeySets as $set) {
					$conditions = [];
					foreach (array_values($throughInnerKeys) as $index => $column) {
						$conditions[$junctionAlias . '.' . $column] = array_values($set)[$index] ?? null;
					}
					$nested->orWhere($conditions);
				}
			});
		}

		if ($this->selection !== null) {
			$this->querySpecCompiler->applyFilters(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->filter,
				$targetAlias,
				$this->aliases
			);
			$this->querySpecCompiler->applySearch(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->search
			);
			$this->querySpecCompiler->applyOrderBy(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->sort,
				$targetAlias
			);
		}

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubqueryWithColumns(
				$query,
				$selectColumns,
				$this->resultNodeColumns(),
				$junctionAlias . '.' . $throughInnerKeys[0]
			);
		}

		$this->parseLoadedRows($node, $query);

		return null;
	}

	private function resultNodeColumns(): array
	{
		$columns = $this->getSelectColumns();
		foreach ($this->pivotNodeColumns() as $column) {
			$columns[] = $column;
		}

		return array_values(array_unique($columns));
	}

	private function selectColumns(string $targetAlias, string $junctionAlias): array
	{
		$columns = [];
		foreach ($this->getSelectColumns() as $column) {
			$columns[] = new Expression($targetAlias . '.' . $column);
		}
		foreach ($this->pivotNodeColumns() as $column) {
			$columns[] = new Expression($junctionAlias . '.' . $column);
		}

		return $columns;
	}

	private function pivotNodeColumns(): array
	{
		return array_values(array_unique([...$this->throughInnerKeyColumns(), ...$this->pivotPrimaryKeyColumns()]));
	}

	private function pivotPrimaryKeyColumns(): array
	{
		$columns = [];
		foreach ($this->manyToMany->through->getCollection()->getPrimaryKey()->getFields() as $field) {
			$columns[] = $field->getColumn();
		}

		return $columns !== []
			? $columns
			: [...$this->throughInnerKeyColumns(), ...$this->throughOuterKeyColumns()];
	}

	private function throughInnerKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->manyToMany->through->getCollection()->fields->get($fieldName)->getColumn(),
			$this->manyToMany->through->throughInnerKeys()
		);
	}

	private function junctionAlias(): string
	{
		return $this->junctionAlias ??= $this->aliases->alias('__on_' . $this->getResponseName() . '_junction');
	}

	private function targetAlias(): string
	{
		return $this->targetAlias ??= $this->aliases->alias('__on_' . $this->getResponseName() . '_target');
	}

	protected function orderByTableAlias(): ?string
	{
		return $this->targetAlias();
	}

	private function relationInnerKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->getCollection()->fields->get($fieldName)->getColumn(),
			$this->relation->innerKeys()
		);
	}

	private function throughOuterKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->manyToMany->through->getCollection()->fields->get($fieldName)->getColumn(),
			$this->manyToMany->through->throughOuterKeys()
		);
	}

	private function targetOuterKeyColumns(): array
	{
		return array_map(
			fn(string $fieldName): string => $this->getTargetCollection()->fields->get($fieldName)->getColumn(),
			$this->relation->outerKeys()
		);
	}
}
