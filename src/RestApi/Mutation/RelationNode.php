<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Handler\MutationHandlerInterface;

final readonly class RelationNode
{
	/**
	 * @param array{create: list<MutationNode>, update: list<MutationNode>, delete: list<MutationNode>} $children
	 */
	public function __construct(
		public MutationHandlerInterface $handler,
		public RelationMutationPayload $payload,
		public MutationStateInterface $state,
		public array $path,
		public array $children,
	) {
	}
}
