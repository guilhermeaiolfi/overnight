<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;

class HasOneLoader extends AbstractRelationLoader
{
	public function configureNode(AbstractNode $parent): AbstractNode
	{
		$node = new SingularNode(
			$this->getSelectColumns(),
			[$this->getPrimaryKeyColumn($this->getTargetCollection())],
			[(string) $this->relation->getOuterKey()],
			[(string) $this->relation->getInnerKey()]
		);
		$parent->linkNode($this->responseName, $node);
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
			->where((string) $this->relation->getOuterKey(), 'IN', $parentIds);
		$this->applyRelationQueryOptions($query);

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->getTargetCollection()->getTable() . '.' . (string) $this->relation->getOuterKey()
			);
		}

		$this->parseLoadedRows($node, $query);
	}
}
