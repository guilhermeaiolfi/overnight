<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\DeleteAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\MutationContext;
use ON\RestApi\Payload\PayloadNormalizer;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKeyCriteria;

trait BelongsToNormalize
{
	use RelationPayloadNormalizeEntry;

	protected function coerceBasicActions(MutationContext $context, BasicRelationAction $basic): array
	{
		$input = $basic->item ?? ($basic->items[0] ?? null);
		$actions = [];
		$targetCollection = $this->getTargetCollection();
		$targetName = $targetCollection->getName();
		$currentParent = $context->parentOperation === 'create' ? null : $this->getCurrentParentRow($context->source);
		$currentId = is_array($currentParent) ? $this->getTargetIdentityFromSourceRow($currentParent) : null;

		if (is_array($input) && MutationInput::isAssociativeArray($input)) {
			$id = $targetCollection->getPrimaryKey()->extract($input);
			if ($id === null && $currentId !== null) {
				$input += $currentId->getValues();
				$id = $currentId;
			}

			if (
				$currentId !== null
				&& $id !== null
				&& (string) $currentId !== ($id instanceof PrimaryKeyValue ? $id->toUrlId() : (string) $id)
			) {
				$actions[] = new DisconnectAction(collection: $targetName, target: $currentId);
				$actions[] = new ConnectAction(collection: $targetName, target: $id);
			}

			if ($id === null) {
				$actions[] = new CreateAction(collection: $targetName, data: $input);
			} else {
				$actions[] = new UpdateAction(collection: $targetName, data: $input);
			}

			return $actions;
		}

		if ($input === null) {
			if ($currentId !== null) {
				$actions[] = new DisconnectAction(collection: $targetName, target: $currentId);
			}

			return $actions;
		}

		if (!is_array($input)) {
			if ($currentId !== null && $currentId !== $input) {
				$actions[] = new DisconnectAction(collection: $targetName, target: $currentId);
			}

			$actions[] = new ConnectAction(collection: $targetName, target: $input);
		}

		return $actions;
	}

	protected function resolvePayloadAction(
		MutationContext $context,
		RelationAction $action,
		PayloadNormalizer $normalizer,
	): void {
		$targetName = $this->getTargetCollection()->getName();

		match (true) {
			$action instanceof CreateAction => $this->resolveEntityAction(
				$action,
				'create',
				$normalizer,
				$context,
				$targetName,
				applySourceValuesOnCreate: false,
			),
			$action instanceof UpdateAction => $this->resolveEntityAction(
				$action,
				'update',
				$normalizer,
				$context,
				$targetName,
				applySourceValuesOnCreate: false,
			),
			$action instanceof DeleteAction => $this->resolveDetailedDelete($action, $targetName),
			$action instanceof ConnectAction => $this->resolveDetailedConnect($action, $context, $targetName),
			$action instanceof DisconnectAction => $this->resolveDisconnectAction($action, $targetName),
			default => null,
		};
	}

	private function resolveDetailedDelete(DeleteAction $action, string $targetCollection): void
	{
		$action->collection ??= $targetCollection;

		if ($action->target === null && is_array($action->data)) {
			return;
		}

		if ($action->target === null && $action->data === null) {
			return;
		}

		if ($action->data === null && $action->target !== null) {
			$collection = $this->getTargetCollection();
			$action->data = PrimaryKeyCriteria::normalize($collection, $action->target)->getValues();
		}
	}

	private function resolveDetailedConnect(ConnectAction $action, MutationContext $context, string $targetCollection): void
	{
		$action->collection ??= $targetCollection;

		if ($action->target !== null) {
			return;
		}

		if (!is_array($action->data)) {
			return;
		}

		$collection = $context->source->getCollection()->getRegistry()->getCollection($targetCollection);
		$id = $collection->getPrimaryKey()->extract($action->data);
		if ($id !== null) {
			$action->target = $id;
		}
	}
}
