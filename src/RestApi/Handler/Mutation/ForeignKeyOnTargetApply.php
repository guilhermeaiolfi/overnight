<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\OperationQueue;
use ON\RestApi\Mutation\NodeStateInterface;
use ON\RestApi\Mutation\RelationNode;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait ForeignKeyOnTargetApply
{
	use RelationApplySupport;

	public function applyRelation(
		OperationQueue $queue,
		NodeStateInterface $source,
		RelationNode $relation
	): void {
		foreach ($relation->children as $child) {
			if (
				$child->relationIntent === 'desired'
				&& ! in_array($child->operation, ['create', 'update', 'delete'], true)
				&& $child->inputIdentity !== null
				&& ($child->currentIdentity === null || $child->currentIdentity->toUrlId() !== $child->inputIdentity->toUrlId())
			) {
				$this->applyConnectionUpdate($queue, $source, $child->inputIdentity, false);
			}

			if ($child->relationIntent === 'omitted' && $child->currentIdentity !== null && ! in_array($child->operation, ['create', 'update', 'delete'], true)) {
				$this->applyConnectionUpdate($queue, $source, $child->currentIdentity, true);
			}
		}
	}

	private function applyConnectionUpdate(
		OperationQueue $queue,
		NodeStateInterface $source,
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

	private function connectionUpdatePayload(NodeStateInterface $source, bool $disconnect): array
	{
		$payload = [];
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$payload[$outerKey] = $disconnect ? null : $source->getValue($this->relation->innerKeys()[$index]);
		}

		return $payload;
	}
}
