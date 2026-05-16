<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;

class HasOneLoader extends AbstractRelationLoader
{
	public function configureNode(AbstractNode $parent, string $name): AbstractNode
	{
		$node = new SingularNode(
			$this->load->getSelectColumns(),
			[$this->getPrimaryKeyColumn($this->load->targetCollection)],
			[(string) $this->load->relation->getOuterKey()],
			[(string) $this->load->relation->getInnerKey()]
		);
		$parent->linkNode($name, $node);

		return $node;
	}

	public function load(AbstractNode $node): void
	{
		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return;
		}

		$columns = $this->load->getSelectColumns();
		$query = $this->baseQuery($columns)
			->where((string) $this->load->relation->getOuterKey(), 'IN', $parentIds);
		$this->applyRelationQueryOptions($query);

		if ($this->load->limit() !== null || $this->load->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->load->targetCollection->getTable() . '.' . (string) $this->load->relation->getOuterKey()
			);
		}

		$this->parseLoadedRows($node, $query);
	}
}
