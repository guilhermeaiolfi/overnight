<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Resolver\DataSourceInterface;

class BelongsToHandler extends HasOneHandler
{
	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		DataSourceInterface $dataSource
	): array {
		$payload = $this->emptyMutationPayload();
		$currentParent = $operation === 'create' ? null : $this->currentParentRow($dataSource, $source);
		$currentId = is_array($currentParent) ? ($currentParent[$this->relation->getInnerField()->getName()] ?? null) : null;

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedPayload($input);
		}

		if (is_array($input) && $this->isAssociativeArray($input)) {
			$targetCollection = $this->relation->getCollection();
			$id = $this->inputPrimaryKeyValue($targetCollection, $input);
			if ($id === null && $currentId !== null) {
				$input[$this->getPrimaryKeyName($targetCollection)] = $currentId;
				$id = $currentId;
			}

			if ($currentId !== null && $id !== null && $currentId !== $id) {
				$payload['disconnect'][] = $currentId;
				$payload['connect'][] = $id;
			}

			$id === null
				? $payload['create'][] = $input
				: $payload['update'][] = $input;

			return $payload;
		}

		if ($input === null) {
			if ($currentId !== null) {
				$payload['disconnect'][] = $currentId;
			}

			return $payload;
		}

		if (!is_array($input)) {
			if ($currentId !== null && $currentId !== $input) {
				$payload['disconnect'][] = $currentId;
			}

			$payload['connect'][] = $input;
		}

		return $payload;
	}

	protected function compileMutationPayload(
		array $payload,
		MutationStateInterface $source,
		array $children,
		MutationQueue $queue
	): void {
		$innerField = $this->relation->getInnerField()->getName();
		$outerField = $this->relation->getOuterField()->getName();

		if (($payload['connect'] ?? []) !== []) {
			$source->setValue($innerField, reset($payload['connect']));
			return;
		}

		if (($payload['disconnect'] ?? []) !== []) {
			$source->setValue($innerField, null);
			return;
		}

		foreach (['create', 'update'] as $operation) {
			foreach ($children[$operation] ?? [] as $target) {
				if ($target instanceof MutationStateInterface) {
					$source->setValue($innerField, $target->getValue($outerField));
					break 2;
				}
			}
		}

		$this->queueChildMutations($children, $queue);
	}
}
