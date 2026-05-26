<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\DeleteAction;
use ON\RestApi\Payload\Action\DisconnectAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\MutationContext;
use ON\RestApi\Payload\PayloadNormalizer;

trait RelationPayloadNormalizeSupport
{
	protected function resolveEntityAction(
		CreateAction|UpdateAction $action,
		string $operation,
		PayloadNormalizer $normalizer,
		MutationContext $context,
		string $targetCollection,
		bool $applySourceValuesOnCreate = true,
	): void {
		if ($action->node !== null) {
			return;
		}

		$data = $action->data ?? [];
		if ($operation === 'create' && $applySourceValuesOnCreate) {
			$this->applySourceValuesToTargetInput($data, $context->source);
		}

		$action->collection = $targetCollection;
		$action->node = $normalizer->normalizeChildNode($targetCollection, $data, $operation);
		$action->data = null;
	}

	protected function resolveConnectAction(ConnectAction $action, MutationContext $context, string $targetCollection): void
	{
		$action->collection ??= $targetCollection;

		if ($action->target !== null || !is_array($action->data)) {
			return;
		}

		$collection = $context->source->getCollection()->getRegistry()->getCollection($targetCollection);
		$id = $collection->getPrimaryKey()->extractFromInput($action->data);
		if ($id !== null) {
			$action->target = $id;
		}
	}

	protected function resolveDeleteAction(DeleteAction $action, string $targetCollection): void
	{
		$action->collection ??= $targetCollection;
	}

	protected function resolveDisconnectAction(DisconnectAction $action, string $targetCollection): void
	{
		$action->collection ??= $targetCollection;
	}

	/**
	 * @return list<RelationAction>
	 */
	protected function omittedChildActions(array $row, string $targetCollection): array
	{
		$actions = [];
		$id = $this->getTargetCollection()->getPrimaryKey()->extractFromInput($row);
		if ($id === null) {
			return $actions;
		}

		if ($this->relation->isCascade() || !$this->relation->isNullable()) {
			$actions[] = new DeleteAction(collection: $targetCollection, data: $id->values());

			return $actions;
		}

		$actions[] = new DisconnectAction(collection: $targetCollection, target: $id);

		return $actions;
	}

	protected static function linkTarget(PrimaryKeyValue|int|string|null $target): PrimaryKeyValue|int|string
	{
		if ($target === null) {
			throw new \InvalidArgumentException('Link target is required.');
		}

		return $target;
	}
}
