<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

final readonly class MutationPlan
{
	public function __construct(
		public MutationNode $root,
	) {
	}
}
