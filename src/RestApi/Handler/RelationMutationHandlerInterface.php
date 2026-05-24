<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Payload\MutationContext;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Payload\PayloadNormalizer;

interface RelationMutationHandlerInterface
{
	public function normalizeRelation(
		RelationPayload $payload,
		MutationContext $context,
		PayloadNormalizer $normalizer,
	): void;

	/**
	 * @param array{create: list<MutationNode>, update: list<MutationNode>, delete: list<MutationNode>} $children
	 */
	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationPayload $relation,
		array $children
	): void;

	public function getTargetCollection(): \ON\ORM\Definition\Collection\CollectionInterface;

	public function getRelationName(): ?string;
}
