<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;

interface RelationMutationHandlerInterface extends MutationHandlerInterface
{
	/**
	 * @param array{create: list<MutationNode>, update: list<MutationNode>, delete: list<MutationNode>} $children
	 */
	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationMutationPayload $payload,
		array $children
	): void;
}
