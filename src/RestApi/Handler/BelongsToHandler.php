<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;

class BelongsToHandler extends HasOneHandler
{
	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		SqlDataSource $dataSource
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

	public function compileActions(
		MutationQueue $queue,
		MutationStateInterface $source,
		array $actions,
		array $children = []
	): \ON\RestApi\Mutation\MutationTaskInterface|\ON\RestApi\Mutation\MutationDeleteTaskInterface|null {
		$innerField = $this->relation->getInnerField()->getName();
		$outerField = $this->relation->getOuterField()->getName();

		if (($actions['connect'] ?? []) !== []) {
			$source->setValue($innerField, reset($actions['connect']));
			return null;
		}

		if (($actions['disconnect'] ?? []) !== []) {
			$source->setValue($innerField, null);
			return null;
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

		return null;
	}
}
