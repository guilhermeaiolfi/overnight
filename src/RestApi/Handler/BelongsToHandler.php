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
		$payload = $this->getEmptyMutationPayload();
		$currentParent = $operation === 'create' ? null : $this->getCurrentParentRow($dataSource, $source);
		$currentId = is_array($currentParent) ? $this->getTargetIdentityFromSourceRow($currentParent) : null;

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedPayload($input);
		}

		if (is_array($input) && $this->isAssociativeArray($input)) {
			$targetCollection = $this->relation->getCollection();
			$id = $this->getInputPrimaryKeyValue($targetCollection, $input);
			if ($id === null && $currentId !== null) {
				$input += $currentId instanceof \ON\ORM\Definition\Collection\PrimaryKeyValue ? $currentId->values() : [];
				$id = $currentId;
			}

			if (
				$currentId !== null
				&& $id !== null
				&& (string) $currentId !== ($id instanceof \ON\ORM\Definition\Collection\PrimaryKeyValue ? $id->toUrlId() : (string) $id)
			) {
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
		if (($actions['connect'] ?? []) !== []) {
			$target = reset($actions['connect']);
			$identity = $target instanceof \ON\ORM\Definition\Collection\PrimaryKeyValue
				? $target
				: \ON\RestApi\Support\PrimaryKeyCriteria::normalize($this->getTargetCollection(), $target);
			foreach ($this->relation->innerKeys() as $index => $key) {
				$source->setValue($key, $identity->value($this->relation->outerKeys()[$index]));
			}
			return null;
		}

		if (($actions['disconnect'] ?? []) !== []) {
			foreach ($this->relation->innerKeys() as $key) {
				$source->setValue($key, null);
			}
			return null;
		}

		foreach (['create', 'update'] as $operation) {
			foreach ($children[$operation] ?? [] as $target) {
				if ($target instanceof MutationStateInterface) {
					$this->setSourceRelationValuesFromTargetState($source, $target);
					break 2;
				}
			}
		}

		$this->queueChildMutations($children, $queue);

		return null;
	}
}
