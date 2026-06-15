<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\ValueRef;

trait RelationApplySupport
{
	use RelationStateSupport;

	protected function linkForeignKeyOnSourceToTarget(
		NodeStateInterface $source,
		NodeStateInterface $target
	): void {
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$outerKey = $this->relation->outerKeys()[$index];
			$source->setValue($innerKey, ValueRef::forStateField($target, $outerKey));
		}
	}
}
