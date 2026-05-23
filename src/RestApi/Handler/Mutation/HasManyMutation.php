<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;
use ON\RestApi\Support\MutationInput;

trait HasManyMutation
{
	use ReferencesForeignKeyMutation;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): RelationMutationPayload {
		$payload = $this->emptyPayload();
		$targetCollection = $this->getTargetCollection();
		if (!is_array($input)) {
			return $payload;
		}

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedHasRelationPayload($input, $source);
		}

		$currentRows = $operation === 'create' ? [] : $this->getCurrentRelationRows($source);
		$currentById = [];
		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = $this->getInputPrimaryKeyValue($targetCollection, $row);
			if ($id !== null) {
				$currentById[$id->toUrlId()] = $row;
			}
		}

		$seen = [];
		foreach (MutationInput::normalizeRelationItems($input) as $item) {
			if (!is_array($item)) {
				$payload->connect[] = $this->linkIntent($item, $targetCollection);
				$seen[(string) $item] = true;
				continue;
			}

			$id = $this->getInputPrimaryKeyValue($targetCollection, $item);
			if ($id === null) {
				$this->applySourceValuesToTargetInput($item, $source);
				$payload->create[] = $this->childIntent($item, $targetCollection);
				continue;
			}

			$key = $id->toUrlId();
			$seen[$key] = true;
			$this->applySourceValuesToTargetInput($item, $source);
			if (isset($currentById[$key])) {
				$payload->update[] = $this->childIntent($item, $targetCollection);
				continue;
			}

			$payload->connect[] = $this->linkIntent($id, $targetCollection);
			if (count($item) > 1) {
				$payload->update[] = $this->childIntent($item, $targetCollection);
			}
		}

		foreach ($currentById as $id => $row) {
			if (!isset($seen[(string) $id])) {
				$this->normalizeOmittedChildren($payload, [$row]);
			}
		}

		return $payload;
	}
}
