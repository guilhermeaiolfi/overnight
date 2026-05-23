<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;

trait HasOneMutation
{
	use ReferencesForeignKeyMutation;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): RelationMutationPayload {
		$payload = $this->emptyPayload();
		$targetCollection = $this->getTargetCollection();

		if ($input === null) {
			if ($operation !== 'create') {
				$this->normalizeOmittedChildren($payload, $this->getCurrentRelationRows($source));
			}

			return $payload;
		}

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedHasRelationPayload($input, $source);
		}

		if (!is_array($input)) {
			$current = $operation === 'create' ? null : ($this->getCurrentRelationRows($source)[0] ?? null);
			$currentId = is_array($current) ? $this->getInputPrimaryKeyValue($targetCollection, $current) : null;
			if ($currentId !== null && $currentId->toUrlId() !== (string) $input) {
				$this->normalizeOmittedChildren($payload, [$current]);
			}
			if ($currentId === null || $currentId->toUrlId() !== (string) $input) {
				$payload->connect[] = $this->linkIntent($input, $targetCollection);
			}

			return $payload;
		}

		$current = $operation === 'create' ? null : ($this->getCurrentRelationRows($source)[0] ?? null);
		$currentId = is_array($current) ? $this->getInputPrimaryKeyValue($targetCollection, $current) : null;
		$desired = $input;
		$desiredId = $this->getInputPrimaryKeyValue($targetCollection, $desired);

		if ($desiredId === null && $currentId !== null) {
			foreach ($currentId->values() as $fieldName => $value) {
				$desired[$fieldName] = $value;
			}
			$desiredId = $currentId;
		}
		if (
			$currentId !== null
			&& $desiredId !== null
			&& $currentId->toUrlId() !== $desiredId->toUrlId()
		) {
			$this->normalizeOmittedChildren($payload, [$current]);
			$payload->connect[] = $this->linkIntent($desiredId, $targetCollection);
		}

		$this->applySourceValuesToTargetInput($desired, $source);
		if ($this->getInputPrimaryKeyValue($targetCollection, $desired) === null) {
			$payload->create[] = $this->childIntent($desired, $targetCollection);
		} else {
			$payload->update[] = $this->childIntent($desired, $targetCollection);
		}

		return $payload;
	}
}
