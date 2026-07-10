<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\ValueRef;

trait RelationApplySupport
{
	use RelationStateSupport;

	protected function linkForeignKeyOnSourceToTarget(
		MutationStateInterface $source,
		MutationStateInterface $target
	): void {
		foreach ($this->relation->getInnerKeys() as $index => $innerKey) {
			$outerKey = $this->relation->getOuterKeys()[$index];
			$source->setValue($innerKey, ValueRef::forStateField($target, $outerKey));
		}
	}
}
