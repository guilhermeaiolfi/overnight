<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\Injection\Expression;
use Cycle\Database\StatementInterface as CycleStatementInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\M2MRelation;
use ON\RestApi\Handler\Mutation\ManyToManyApply;
use ON\RestApi\Handler\Mutation\ManyToManyNormalize;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;

class ManyToManyHandler extends AbstractRelationHandler implements RelationMutationHandlerInterface
{
	use LimitedSubquerySupport;
	use ManyToManyApply;
	use ManyToManyNormalize;

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
		$throughCollection = $this->manyToMany->through->getCollection();
		$node = new ArrayNode(
			$this->resultNodeColumns(),
			$this->pivotPrimaryKeyColumns(),
			$this->fieldNamesToColumnNames($throughCollection, $this->manyToMany->through->getInnerKeys()),
			$this->fieldNamesToColumnNames($this->getCollection(), $this->relation->getInnerKeys())
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): mixed
	{
		$node = $this->getNode();
		$parentKeySets = $this->referenceValueSets($node);
		if ($parentKeySets === []) {
			return null;
		}

		$through = $this->manyToMany->through;
		$throughCollection = $through->getCollection();
		$junctionAlias = $this->junctionAlias();
		$targetAlias = $this->targetAlias();
		$throughInnerKeys = $this->fieldNamesToColumnNames($throughCollection, $through->getInnerKeys());
		$throughOuterKeys = $this->fieldNamesToColumnNames($throughCollection, $through->getOuterKeys());
		$targetKeyColumns = $this->fieldNamesToColumnNames($this->getTargetCollection(), $this->relation->getOuterKeys());

		$selectColumns = $this->selectColumns($targetAlias, $junctionAlias);
		$query = $this->items->getDatabase()->select($selectColumns)
			->from($throughCollection->getTable() . ' AS ' . $junctionAlias)
			->innerJoin($this->getTargetCollection()->getTable(), $targetAlias);
		foreach ($throughOuterKeys as $index => $throughOuterKey) {
			$method = $index === 0 ? 'on' : 'andOn';
			$query->{$method}($junctionAlias . '.' . $throughOuterKey, '=', $targetAlias . '.' . $targetKeyColumns[$index]);
		}
		if (count($throughInnerKeys) === 1) {
			$query->where($junctionAlias . '.' . $throughInnerKeys[0], 'IN', array_map(
				static fn (array $set): mixed => reset($set),
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

		$limit = $this->selectionPagination()?->limit;
		$offset = $this->selectionPagination()?->offset;
		if ($limit !== null || $offset !== null) {
			$query = $this->limitedSubquery(
				$query,
				$this->resultNodeColumns(),
				array_map(
					fn (string $column): string => $junctionAlias . '.' . $column,
					$throughInnerKeys
				),
				$this->selectionWindowOrderBy($targetAlias),
				$limit,
				$offset
			);
		}

		$this->parseRows($node, $query->fetchAll(CycleStatementInterface::FETCH_NUM));

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
		$through = $this->manyToMany->through;
		$throughCollection = $through->getCollection();

		return array_values(array_unique([
			...$this->fieldNamesToColumnNames($throughCollection, $through->getInnerKeys()),
			...$this->pivotPrimaryKeyColumns(),
		]));
	}

	private function pivotPrimaryKeyColumns(): array
	{
		$through = $this->manyToMany->through;
		$throughCollection = $through->getCollection();
		$columns = $throughCollection->hasPrimaryKey()
			? $throughCollection->getPrimaryKeyColumns()
			: [];

		return $columns !== []
			? $columns
			: [
				...$this->fieldNamesToColumnNames($throughCollection, $through->getInnerKeys()),
				...$this->fieldNamesToColumnNames($throughCollection, $through->getOuterKeys()),
			];
	}

	private function junctionAlias(): string
	{
		return $this->junctionAlias ??= $this->aliases->alias('__on_' . $this->getResponseName() . '_junction');
	}

	private function targetAlias(): string
	{
		return $this->targetAlias ??= $this->aliases->alias('__on_' . $this->getResponseName() . '_target');
	}
}
