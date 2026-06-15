<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\RecordNode;

/**
 * Performs final structural validation on the compiled mutation plan before execution.
 */
final class ValidateMutationPlan implements HydrationPassInterface
{
	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('ValidateMutationPlan requires a record node.');
		}

		foreach ($subject->relations as $relation) {
			if ($relation->handler === null && $relation->children !== []) {
				throw new RestApiError(
					"Relation '{$relation->relationName}' cannot be mutated.",
					'READ_ONLY_RELATION',
					$relation->relationName,
					400
				);
			}
		}

		return $subject;
	}
}
