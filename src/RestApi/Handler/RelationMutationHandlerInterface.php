<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\OperationQueue;
use ON\RestApi\Mutation\RelationNode;

interface RelationMutationHandlerInterface
{
	public function reconcileRelation(RecordNode $source, RelationNode $relation): void;

	public function applyRelation(
		OperationQueue $queue,
		NodeStateInterface $source,
		RelationNode $relation
	): void;

	public function getTargetCollection(): \ON\ORM\Definition\Collection\CollectionInterface;

	public function getRelationName(): ?string;
}
