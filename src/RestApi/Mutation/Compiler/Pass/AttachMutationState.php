<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\NodeStateInterface;

/**
 * Rebinds value references for the current branch and propagates state/path information to relations.
 */
final class AttachMutationState implements HydrationPassInterface
{
	use MutationStateIdentity;

	public function __construct(
		private readonly ?NodeStateInterface $parentState = null,
	) {
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('AttachMutationState requires a record node.');
		}

		if ($this->parentState !== null) {
			$subject->fields = $this->parentState->rebindValueRefs($subject->fields);
		}

		$identity = $subject->state->getPrimaryKeyValue(false);
		$subject->syncState();
		if ($subject->operation === 'update' && $identity !== null) {
			$this->applyPrimaryKeyValues($subject->state, $identity);
		}

		foreach ($subject->relations as $relation) {
			$relation->state = $subject->state;
			$relation->path = [...$subject->path, $relation->relationName];
		}

		return $subject;
	}
}
