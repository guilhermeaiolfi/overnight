<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;

class BelongsToLoader extends HasOneLoader
{
	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): array {
		$payload = [
			'create' => [],
			'update' => [],
			'delete' => [],
			'connect' => [],
			'disconnect' => [],
		];
		if (is_array($input) && $this->isAssociativeArray($input) && $this->hasOperationPayload($input)) {
			foreach (['create', 'update', 'delete', 'connect', 'disconnect'] as $key) {
				$payload[$key] = $this->normalizeRelationItems($input[$key] ?? []);
			}

			return $payload;
		}

		if (is_array($input) && $this->isAssociativeArray($input)) {
			$targetCollection = $this->relation->getCollection();
			$this->inputPrimaryKeyValue($targetCollection, $input) === null
				? $payload['create'][] = $input
				: $payload['update'][] = $input;

			return $payload;
		}

		if (!is_array($input)) {
			$payload['connect'][] = $input;
		}

		return $payload;
	}

	protected function mutate(
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
