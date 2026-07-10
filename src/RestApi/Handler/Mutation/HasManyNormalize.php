<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

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
use ON\RestApi\Support\PrimaryKey;

trait HasManyNormalize
{
	use RelationPayloadNormalizeEntry;

	protected function coerceBasicActions(MutationContext $context, BasicRelationAction $basic): array
	{
		$input = $basic->items;
		$actions = [];
		$targetCollection = $this->getTargetCollection();
		$targetName = $targetCollection->getName();

		if (! is_array($input)) {
			return [new ConnectAction(collection: $targetName, target: $input)];
		}

		$currentRows = $context->parentOperation === 'create' ? [] : $this->getCurrentRelationRows($context->source);
		$currentById = [];
		foreach ($currentRows as $row) {
			if (! is_array($row)) {
				continue;
			}

			$id = PrimaryKey::of($targetCollection)->extractFromInput($row);
			if ($id !== null) {
				$currentById[$id->toUrlId()] = $row;
			}
		}

		$seen = [];
		foreach (MutationInput::normalizeRelationItems($input) as $index => $item) {
			if (! is_array($item)) {
				$actions[] = new ConnectAction(collection: $targetName, target: $item, index: $index);
				$seen[(string) $item] = true;

				continue;
			}

			$id = PrimaryKey::of($targetCollection)->extractFromInput($item);
			if ($id === null) {
				$itemCopy = $item;
				$this->applySourceValuesToTargetInput($itemCopy, $context->source);
				$actions[] = new CreateAction(collection: $targetName, data: $itemCopy, index: $index);

				continue;
			}

			$key = $id->toUrlId();
			$seen[$key] = true;
			$itemCopy = $item;
			$this->applySourceValuesToTargetInput($itemCopy, $context->source);

			if (isset($currentById[$key])) {
				$actions[] = new UpdateAction(collection: $targetName, data: $itemCopy, index: $index);

				continue;
			}

			if (count($itemCopy) > 1) {
				$actions[] = new ConnectAction(collection: $targetName, target: $id, index: $index);
				$actions[] = new UpdateAction(collection: $targetName, data: $itemCopy, index: $index);

				continue;
			}

			$actions[] = new ConnectAction(collection: $targetName, target: $id, index: $index);
		}

		foreach ($currentById as $id => $row) {
			if (isset($seen[(string) $id])) {
				continue;
			}

			$actions = array_merge($actions, $this->omittedChildActions($row, $targetName));
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
			$action instanceof CreateAction => $this->resolveEntityAction($action, 'create', $normalizer, $context, $targetName),
			$action instanceof UpdateAction => $this->resolveEntityAction($action, 'update', $normalizer, $context, $targetName, applySourceValuesOnCreate: false),
			$action instanceof DeleteAction => $this->resolveDeleteAction($action, $targetName),
			$action instanceof ConnectAction => $this->resolveConnectAction($action, $context, $targetName),
			$action instanceof DisconnectAction => $this->resolveDisconnectAction($action, $targetName),
			default => null,
		};
	}
}
