<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Support\PrimaryKeyCriteria;
use ON\RestApi\Support\PrimaryKeyValue;

trait BelongsToApply
{
	use RelationApplySupport;

	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationPayload $relation,
		array $children
	): void {
		foreach ($relation->actions as $action) {
			if ($action instanceof ConnectAction && $action->target !== null) {
				$identity = $action->target instanceof PrimaryKeyValue
					? $action->target
					: PrimaryKeyCriteria::normalize($this->getTargetCollection(), $action->target);
				foreach ($this->relation->getInnerKeys() as $index => $key) {
					$source->setValue($key, $identity->value($this->relation->getOuterKeys()[$index]));
				}

				return;
			}
		}

		foreach ($relation->actions as $action) {
			if ($action instanceof DisconnectAction) {
				foreach ($this->relation->getInnerKeys() as $key) {
					$source->setValue($key, null);
				}

				return;
			}
		}

		foreach (['create', 'update'] as $operation) {
			foreach ($children[$operation] ?? [] as $child) {
				if ($child instanceof MutationNode) {
					$this->linkForeignKeyOnSourceToTarget($source, $child->state);

					return;
				}
			}
		}
	}
}
