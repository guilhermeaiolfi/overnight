<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Expander;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;

abstract class AbstractRelationPayloadExpander
{
	use RelationExpanderSupport;

	public function __construct(
		protected CollectionInterface $collection,
		protected RelationInterface $relation,
		protected ItemRepositoryInterface $items,
	) {
	}

	protected function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	protected function getTargetCollection(): CollectionInterface
	{
		return $this->relation->getCollection();
	}
}
