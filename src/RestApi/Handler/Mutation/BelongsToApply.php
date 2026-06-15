<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\OperationQueue;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\RelationNode;

trait BelongsToApply
{
	use RelationApplySupport;

	public function applyRelation(
		OperationQueue $queue,
		NodeStateInterface $source,
		RelationNode $relation
	): void {
		foreach ($relation->children as $child) {
			if (in_array($child->operation, ['create', 'update'], true) && $child->state !== null) {
				$this->linkForeignKeyOnSourceToTarget($source, $child->state);

				return;
			}
		}

		foreach ($relation->children as $child) {
			if (
				$child->relationIntent === 'desired'
				&& $child->inputIdentity !== null
				&& ($child->currentIdentity === null || $child->currentIdentity->toUrlId() !== $child->inputIdentity->toUrlId())
			) {
				foreach ($this->relation->innerKeys() as $index => $key) {
					$source->setValue($key, $child->inputIdentity->getValue($this->relation->outerKeys()[$index]));
				}

				return;
			}
		}

		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' || $child->operation === 'delete') {
				foreach ($this->relation->innerKeys() as $key) {
					$source->setValue($key, null);
				}

				return;
			}
		}
	}
}
