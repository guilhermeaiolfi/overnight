<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\DeleteAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\MutationContext;
use ON\RestApi\Payload\PayloadNormalizer;

trait ManyToManyNormalize
{
	use RelationPayloadNormalizeEntry;

	protected function coerceBasicActions(MutationContext $context, BasicRelationAction $basic): array
	{
		$input = $basic->item ?? $basic->items;
		$actions = [];
		$manyToMany = $this->getManyToManyRelation();
		$throughCollection = $manyToMany->through->getCollection();
		$targetCollection = $this->getTargetCollection();
		$targetName = $targetCollection->getName();
		$throughName = $throughCollection->getName();
		$throughPrimaryKey = $throughCollection->getPrimaryKey()->getFieldNames()[0] ?? 'id';
		$currentRows = $context->parentOperation === 'create' ? [] : $this->currentPivotRows($context->source);
		$currentByPivotId = [];
		$currentByTargetId = [];

		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$pivotId = $this->getInputPrimaryKeyValue($throughCollection, $row);
			$targetId = $this->extractThroughTargetIdentity($row);
			if ($pivotId !== null) {
				$currentByPivotId[$pivotId->toUrlId()] = $row;
			}
			if ($targetId !== null) {
				$currentByTargetId[$targetId->toUrlId()] = $row;
			}
		}

		if (!is_array($input)) {
			return [new ConnectAction(collection: $targetName, target: $input)];
		}

		$seenPivot = [];
		$seenTarget = [];
		foreach ($input as $index => $item) {
			if (!is_array($item)) {
				$actions[] = new ConnectAction(collection: $targetName, target: $item, index: $index);
				$seenTarget[(string) $item] = true;
				continue;
			}

			if ($this->isThroughPayload($item)) {
				$actions = array_merge(
					$actions,
					$this->coerceThroughItem($context, $item, $index, $throughName, $throughPrimaryKey, $currentByPivotId, $currentByTargetId, $seenPivot, $seenTarget)
				);
				continue;
			}

			$targetId = $this->getInputPrimaryKeyValue($targetCollection, $item);
			if ($targetId === null) {
				$actions[] = new CreateAction(collection: $targetName, data: $item, index: $index);
				continue;
			}

			$seenTarget[$targetId->toUrlId()] = true;
			if (isset($currentByTargetId[$targetId->toUrlId()])) {
				$actions[] = new UpdateAction(collection: $targetName, data: $item, index: $index);
				continue;
			}

			$actions[] = new ConnectAction(collection: $targetName, target: $targetId, index: $index);
			if (count($item) > 1) {
				$actions[] = new UpdateAction(collection: $targetName, data: $item, index: $index);
			}
		}

		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$pivotId = $this->getInputPrimaryKeyValue($throughCollection, $row);
			$targetId = $this->extractThroughTargetIdentity($row);
			if ($pivotId !== null && isset($seenPivot[$pivotId->toUrlId()])) {
				continue;
			}
			if ($targetId !== null && isset($seenTarget[$targetId->toUrlId()])) {
				continue;
			}

			if ($targetId !== null) {
				$actions[] = new DisconnectAction(collection: $targetName, target: $targetId);
			}
		}

		return $actions;
	}

	protected function resolvePayloadAction(
		MutationContext $context,
		RelationAction $action,
		PayloadNormalizer $normalizer,
	): void {
		$targetName = $this->getTargetCollection()->getName();
		$throughName = $this->getManyToManyRelation()->through->getCollection()->getName();

		match (true) {
			$action instanceof CreateAction => $this->resolveEntityPayloadAction($action, $context, $normalizer, $targetName, $throughName, 'create'),
			$action instanceof UpdateAction => $this->resolveEntityPayloadAction($action, $context, $normalizer, $targetName, $throughName, 'update'),
			$action instanceof DeleteAction => $this->resolveDeleteAction($action, $targetName),
			$action instanceof ConnectAction => $this->resolveConnectAction($action, $context, $targetName),
			$action instanceof DisconnectAction => $this->resolveDisconnectAction($action, $targetName),
			default => null,
		};
	}

	protected function getManyToManyRelation(): M2MRelation
	{
		if (!$this->relation instanceof M2MRelation) {
			throw new \LogicException('Expected M2M relation.');
		}

		return $this->relation;
	}

	private function resolveEntityPayloadAction(
		CreateAction|UpdateAction $action,
		MutationContext $context,
		PayloadNormalizer $normalizer,
		string $targetName,
		string $throughName,
		string $operation,
	): void {
		if ($action->node !== null) {
			return;
		}

		$data = $action->data ?? [];
		$collectionName = is_array($data) && $this->isThroughPayload($data) ? $throughName : $targetName;
		if ($collectionName === $throughName) {
			$data = $this->normalizeThroughPayload($context->source, $data);
		}

		$action->collection = $collectionName;
		$action->node = $normalizer->normalizeChildNode($collectionName, $data, $operation);
		$action->data = null;
	}

	/**
	 * @param array<string, array> $currentByPivotId
	 * @param array<string, array> $currentByTargetId
	 * @param array<string, true> $seenPivot
	 * @param array<string, true> $seenTarget
	 * @return list<RelationAction>
	 */
	private function coerceThroughItem(
		MutationContext $context,
		array $item,
		int $index,
		string $throughName,
		string $throughPrimaryKey,
		array $currentByPivotId,
		array $currentByTargetId,
		array &$seenPivot,
		array &$seenTarget,
	): array {
		$actions = [];
		$manyToMany = $this->getManyToManyRelation();
		$pivotId = array_key_exists($throughPrimaryKey, $item) ? $item[$throughPrimaryKey] : null;
		$targetId = $this->extractThroughTargetIdentity($item);
		$normalized = $this->normalizeThroughPayload($context->source, $item);

		if ($pivotId !== null && isset($currentByPivotId[(string) $pivotId])) {
			$key = $pivotId instanceof PrimaryKeyValue ? $pivotId->toUrlId() : (string) $pivotId;
			$seenPivot[$key] = true;
			$actions[] = new UpdateAction(collection: $throughName, data: $normalized, index: $index);

			return $actions;
		}

		if ($targetId !== null && isset($currentByTargetId[$targetId->toUrlId()])) {
			$existing = $currentByTargetId[$targetId->toUrlId()];
			$existingPivotId = $this->getInputPrimaryKeyValue($manyToMany->through->getCollection(), $existing);
			if ($existingPivotId !== null) {
				$seenPivot[$existingPivotId->toUrlId()] = true;
				foreach ($existingPivotId->values() as $fieldName => $value) {
					$normalized[$fieldName] = $value;
				}
			}
			$seenTarget[$targetId->toUrlId()] = true;
			$actions[] = new UpdateAction(collection: $throughName, data: $normalized, index: $index);

			return $actions;
		}

		if ($targetId !== null) {
			$seenTarget[$targetId->toUrlId()] = true;
		}
		$actions[] = new CreateAction(collection: $throughName, data: $normalized, index: $index);

		return $actions;
	}

	private function isThroughPayload(array $item): bool
	{
		$manyToMany = $this->getManyToManyRelation();
		$through = $manyToMany->through->getCollection();
		$target = $this->getTargetCollection();

		foreach (array_keys($item) as $key) {
			if (in_array((string) $key, $manyToMany->through->throughOuterKeys(), true)) {
				return true;
			}

			if ($through->fields->has((string) $key) && !$target->fields->has((string) $key)) {
				return true;
			}
		}

		return false;
	}

	private function normalizeThroughPayload(MutationStateInterface $source, array $item): array
	{
		$manyToMany = $this->getManyToManyRelation();
		foreach ($manyToMany->through->throughInnerKeys() as $index => $throughInnerKey) {
			$item[$throughInnerKey] = $source->getValue($this->relation->innerKeys()[$index]);
		}

		return $item;
	}

	private function currentPivotRows(MutationStateInterface $source): array
	{
		$through = $this->getManyToManyRelation()->through;
		$fieldValueMap = [];
		foreach ($this->relation->innerKeys() as $index => $innerKey) {
			$fieldValueMap[$through->throughInnerKeys()[$index]] = $source->resolveValue($source->getValue($innerKey));
		}

		return $this->fetchRowsByFields($through->getCollection(), $fieldValueMap);
	}

	private function extractThroughTargetIdentity(array $row): ?PrimaryKeyValue
	{
		$manyToMany = $this->getManyToManyRelation();
		$values = [];
		foreach ($manyToMany->through->throughOuterKeys() as $index => $throughOuterKey) {
			if (!array_key_exists($throughOuterKey, $row)) {
				return null;
			}

			$values[$this->relation->outerKeys()[$index]] = $row[$throughOuterKey];
		}

		return new PrimaryKeyValue($this->getTargetCollection(), $values);
	}
}
