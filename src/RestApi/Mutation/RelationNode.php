<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Handler\RelationMutationHandlerInterface;
use ON\RestApi\Payload\Node\RelationPayload;

final readonly class RelationNode
{
	/**
	 * @param array{create: list<MutationNode>, update: list<MutationNode>, delete: list<MutationNode>} $children
	 */
	public function __construct(
		public RelationMutationHandlerInterface $handler,
		public RelationPayload $payload,
		public MutationStateInterface $state,
		public array $path,
		public array $children,
	) {
	}
}
