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

trait HasOneNormalize
{
	use RelationPayloadNormalizeEntry;

	protected function coerceBasicActions(MutationContext $context, BasicRelationAction $basic): array
	{
		$input = $basic->item ?? ($basic->items[0] ?? null);
		$actions = [];
		$targetCollection = $this->getTargetCollection();
		$targetName = $targetCollection->getName();

		if ($input === null) {
			if ($context->parentOperation !== 'create') {
				$current = $this->getCurrentRelationRows($context->source)[0] ?? null;
				if (is_array($current)) {
					$actions = array_merge($actions, $this->omittedChildActions($current, $targetName));
				}
			}

			return $actions;
		}

		if (!is_array($input)) {
			$current = $context->parentOperation === 'create' ? null : ($this->getCurrentRelationRows($context->source)[0] ?? null);
			$currentId = is_array($current) ? $targetCollection->getPrimaryKey()->extract($current) : null;
			if ($currentId !== null && $currentId->toUrlId() !== (string) $input) {
				$actions = array_merge($actions, $this->omittedChildActions($current, $targetName));
			}
			if ($currentId === null || $currentId->toUrlId() !== (string) $input) {
				$actions[] = new ConnectAction(collection: $targetName, target: $input);
			}

			return $actions;
		}

		$current = $context->parentOperation === 'create' ? null : ($this->getCurrentRelationRows($context->source)[0] ?? null);
		$currentId = is_array($current) ? $targetCollection->getPrimaryKey()->extract($current) : null;
		$desired = $input;
		$desiredId = $targetCollection->getPrimaryKey()->extract($desired);

		if ($desiredId === null && $currentId !== null) {
			foreach ($currentId->getValues() as $fieldName => $value) {
				$desired[$fieldName] = $value;
			}
			$desiredId = $currentId;
		}

		if (
			$currentId !== null
			&& $desiredId !== null
			&& $currentId->toUrlId() !== $desiredId->toUrlId()
		) {
			$actions = array_merge($actions, $this->omittedChildActions($current, $targetName));
			$actions[] = new ConnectAction(collection: $targetName, target: $desiredId);
		}

		$this->applySourceValuesToTargetInput($desired, $context->source);
		if ($targetCollection->getPrimaryKey()->extract($desired) === null) {
			$actions[] = new CreateAction(collection: $targetName, data: $desired);
		} else {
			$actions[] = new UpdateAction(collection: $targetName, data: $desired);
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
