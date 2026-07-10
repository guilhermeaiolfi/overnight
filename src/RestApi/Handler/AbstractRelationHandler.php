<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;

abstract class AbstractRelationHandler implements RelationMutationHandlerInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected RelationInterface $relation,
		protected ItemRepositoryInterface $items,
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getTargetCollection(): CollectionInterface
	{
		return $this->relation->getCollection();
	}

	public function getRelationName(): string
	{
		return $this->relation->getName();
	}
}
