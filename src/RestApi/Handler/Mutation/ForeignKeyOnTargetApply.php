<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait ForeignKeyOnTargetApply
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
				$this->applyConnectionUpdate($queue, $source, $action->target, false);
			}

			if ($action instanceof DisconnectAction && $action->target !== null) {
				$this->applyConnectionUpdate($queue, $source, $action->target, true);
			}
		}
	}

	private function applyConnectionUpdate(
		MutationQueue $queue,
		MutationStateInterface $source,
		mixed $target,
		bool $disconnect
	): void {
		$targetCollection = $this->getTargetCollection();

		$queue->queueUpdate(
			$targetCollection,
			PrimaryKeyCriteria::build($targetCollection, $target),
			$this->connectionUpdatePayload($source, $disconnect)
		);
	}

	private function connectionUpdatePayload(MutationStateInterface $source, bool $disconnect): array
	{
		$payload = [];
		foreach ($this->relation->getOuterKeys() as $index => $outerKey) {
			$payload[$outerKey] = $disconnect ? null : $source->getValue($this->relation->getInnerKeys()[$index]);
		}

		return $payload;
	}
}
