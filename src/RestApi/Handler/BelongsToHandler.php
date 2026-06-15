<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\StatementInterface as CycleStatementInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\RestApi\Handler\Mutation\BelongsToApply;
use ON\RestApi\Handler\Mutation\BelongsToCompile;

class BelongsToHandler extends AbstractRelationHandler implements RelationMutationHandlerInterface
{
	use BelongsToApply;
	use BelongsToCompile;
	use LimitedSubquerySupport;

	public function configureParserNode(AbstractNode $parent): AbstractNode
	{
		$node = new SingularNode(
			$this->getSelectColumns(),
			$this->getTargetCollection()->getPrimaryKey()->getColumns(),
			$this->fieldNamesToColumnNames($this->getTargetCollection(), $this->relation->outerKeys()),
			$this->fieldNamesToColumnNames($this->getCollection(), $this->relation->innerKeys())
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

		$columns = $this->getSelectColumns();
		$outerKeyColumns = $this->fieldNamesToColumnNames($this->getTargetCollection(), $this->relation->outerKeys());
		$query = $this->items->getDatabase()->select($columns)
			->from($this->getTargetCollection()->getTable());

		if (count($outerKeyColumns) === 1) {
			$query->where($outerKeyColumns[0], 'IN', array_map(
				static fn(array $set): mixed => reset($set),
				$parentKeySets
			));
		} else {
			$query->where(function ($nested) use ($parentKeySets, $outerKeyColumns) {
				foreach ($parentKeySets as $set) {
					$nested->orWhere(array_combine($outerKeyColumns, array_values($set)));
				}
			});
		}

		if ($this->selection !== null) {
			$this->querySpecCompiler->applyFilters(
				$query,
				$this->getTargetCollection(),
				$this->selection->query->filter,
				null,
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
				$this->selection->query->sort
			);
		}

		$limit = $this->selectionPagination()?->limit;
		$offset = $this->selectionPagination()?->offset;
		if ($limit !== null || $offset !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				array_map(
					fn(string $column): string => $this->getTargetCollection()->getTable() . '.' . $column,
					$outerKeyColumns
				),
				$this->selectionWindowOrderBy(),
				$limit,
				$offset
			);
		}

		$this->parseRows($node, $query->fetchAll(CycleStatementInterface::FETCH_NUM));

		return null;
	}
}
