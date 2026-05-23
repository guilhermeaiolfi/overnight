<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait ReferencesForeignKeyMutation
{
	use RelationMutationSupport;

	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationMutationPayload $payload,
		array $children
	): void {
		foreach ($payload->connect as $target) {
			$this->applyConnectionUpdate($queue, $source, self::linkTarget($target), false);
		}

		foreach ($payload->disconnect as $target) {
			$this->applyConnectionUpdate($queue, $source, self::linkTarget($target), true);
		}
	}

	protected function normalizeOmittedChildren(RelationMutationPayload $payload, array $currentRows): void
	{
		$targetCollection = $this->getTargetCollection();

		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = $this->getInputPrimaryKeyValue($targetCollection, $row);
			if ($id === null) {
				continue;
			}

			if ($this->relation->isCascade() || !$this->relation->isNullable()) {
				$payload->delete[] = $this->childIntent($id->values(), $targetCollection);
				continue;
			}

			$payload->disconnect[] = $this->linkIntent($id, $targetCollection);
		}
	}

	protected function normalizeDetailedHasRelationPayload(array $input, MutationStateInterface $source): RelationMutationPayload
	{
		$payload = $this->normalizeDetailedPayload($input);
		$targetCollection = $this->getTargetCollection();

		foreach (['create', 'update'] as $mutation) {
			foreach ($payload->{$mutation} as $index => $intent) {
				$item = $intent->data;
				if ($mutation === 'create') {
					$this->applySourceValuesToTargetInput($item, $source);
				}

				$payload->{$mutation}[$index] = $this->childIntent($item, $targetCollection);
			}
		}

		return $payload;
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
		foreach ($this->relation->outerKeys() as $index => $outerKey) {
			$payload[$outerKey] = $disconnect ? null : $source->getValue($this->relation->innerKeys()[$index]);
		}

		return $payload;
	}
}
