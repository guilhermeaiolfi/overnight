<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
class HasManyLoader extends HasOneLoader
{
	public function configureNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->getSelectColumns(),
			[$this->getPrimaryKeyColumn($this->getTargetCollection())],
			[$this->relation->getOuterField()->getColumn()],
			[$this->relation->getInnerField()->getColumn()]
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): void
	{
		$node = $this->getNode();
		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return;
		}

		$columns = $this->getSelectColumns();
		$query = $this->baseQuery($columns)
			->where($this->relation->getOuterField()->getColumn(), 'IN', $parentIds);
		$this->applyRelationQueryOptions($query);

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->getTargetCollection()->getTable() . '.' . $this->relation->getOuterField()->getColumn()
			);
		}

		$this->parseLoadedRows($node, $query);
	}
}
