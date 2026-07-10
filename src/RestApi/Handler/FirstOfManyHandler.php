<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\Database\StatementInterface as CycleStatementInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use ON\RestApi\Support\PrimaryKey;

class FirstOfManyHandler extends AbstractRelationHandler
{
	use LimitedSubquerySupport;

	public function configureParserNode(AbstractNode $parent): AbstractNode
	{
		$node = new SingularNode(
			$this->getSelectColumns(),
			PrimaryKey::of($this->getTargetCollection())->getColumns(),
			$this->fieldNamesToColumnNames($this->getTargetCollection(), $this->relation->getOuterKeys()),
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

		$columns = $this->getSelectColumns();
		$outerKeyColumns = $this->fieldNamesToColumnNames($this->getTargetCollection(), $this->relation->getOuterKeys());
		$query = $this->items->getDatabase()->select($columns)
			->from($this->getTargetCollection()->getTable());

		if (count($outerKeyColumns) === 1) {
			$query->where($outerKeyColumns[0], 'IN', array_map(
				static fn (array $set): mixed => reset($set),
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
		}

		$query = $this->limitedSubquery(
			$query,
			$columns,
			array_map(
				fn (string $column): string => $this->getTargetCollection()->getTable() . '.' . $column,
				$outerKeyColumns
			),
			$this->orderBy(),
			$this->limit(),
			$this->selectionPagination()?->offset
		);

		$this->parseRows($node, $query->fetchAll(CycleStatementInterface::FETCH_NUM));

		return null;
	}

	public function isSingle(): bool
	{
		return true;
	}

	public function limit(): ?int
	{
		return 1;
	}

	public function orderBy(): array
	{
		$table = $this->getTargetCollection()->getTable();
		$orders = [];
		foreach ($this->relation->getOrderBy() as $fieldName => $direction) {
			if (! $this->getTargetCollection()->fields->has((string) $fieldName)) {
				continue;
			}

			$column = $this->getTargetCollection()->fields->get((string) $fieldName)->getColumn();
			$direction = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
			$orders[] = $table . '.' . $column . ' ' . $direction;
		}

		return $orders;
	}
}
