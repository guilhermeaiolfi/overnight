<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait BelongsToMutation
{
	use RelationMutationSupport;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): RelationMutationPayload {
		$payload = $this->emptyPayload();
		$targetCollection = $this->getTargetCollection();
		$currentParent = $operation === 'create' ? null : $this->getCurrentParentRow($source);
		$currentId = is_array($currentParent) ? $this->getTargetIdentityFromSourceRow($currentParent) : null;

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedPayload($input, $targetCollection);
		}

		if (is_array($input) && MutationInput::isAssociativeArray($input)) {
			$id = $this->getInputPrimaryKeyValue($targetCollection, $input);
			if ($id === null && $currentId !== null) {
				$input += $currentId instanceof PrimaryKeyValue ? $currentId->values() : [];
				$id = $currentId;
			}

			if (
				$currentId !== null
				&& $id !== null
				&& (string) $currentId !== ($id instanceof PrimaryKeyValue ? $id->toUrlId() : (string) $id)
			) {
				$payload->disconnect[] = $this->linkIntent($currentId, $targetCollection);
				$payload->connect[] = $this->linkIntent($id, $targetCollection);
			}

			if ($id === null) {
				$payload->create[] = $this->childIntent($input, $targetCollection);
			} else {
				$payload->update[] = $this->childIntent($input, $targetCollection);
			}

			return $payload;
		}

		if ($input === null) {
			if ($currentId !== null) {
				$payload->disconnect[] = $this->linkIntent($currentId, $targetCollection);
			}

			return $payload;
		}

		if (!is_array($input)) {
			if ($currentId !== null && $currentId !== $input) {
				$payload->disconnect[] = $this->linkIntent($currentId, $targetCollection);
			}

			$payload->connect[] = $this->linkIntent($input, $targetCollection);
		}

		return $payload;
	}

	public function applyRelation(
		MutationQueue $queue,
		MutationStateInterface $source,
		RelationMutationPayload $payload,
		array $children
	): void {
		if ($payload->hasConnect()) {
			$target = self::linkTarget(reset($payload->connect));
			$identity = $target instanceof PrimaryKeyValue
				? $target
				: PrimaryKeyCriteria::normalize($this->getTargetCollection(), $target);
			foreach ($this->relation->innerKeys() as $index => $key) {
				$source->setValue($key, $identity->value($this->relation->outerKeys()[$index]));
			}

			return;
		}

		if ($payload->hasDisconnect()) {
			foreach ($this->relation->innerKeys() as $key) {
				$source->setValue($key, null);
			}

			return;
		}

		foreach (['create', 'update'] as $operation) {
			foreach ($children[$operation] ?? [] as $child) {
				if ($child instanceof MutationNode) {
					$this->setSourceRelationValuesFromTargetState($source, $child->state);
					return;
				}
			}
		}
	}
}
