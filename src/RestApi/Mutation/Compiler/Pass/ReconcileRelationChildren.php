<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\RecordNode;

/**
 * Lets each relation handler normalize and finalize its relation children in one step.
 */
final class ReconcileRelationChildren implements HydrationPassInterface
{
	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('ReconcileRelationChildren requires a record node.');
		}

		foreach ($subject->relations as $relation) {
			if ($relation->handler === null) {
				continue;
			}

			$relation->handler->reconcileRelation($subject, $relation);
		}

		return $subject;
	}
}
