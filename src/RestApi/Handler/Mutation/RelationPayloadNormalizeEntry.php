<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\MutationContext;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Payload\PayloadNormalizer;

trait RelationPayloadNormalizeEntry
{
	use RelationNormalizeSupport;
	use RelationPayloadNormalizeSupport;

	public function normalizeRelation(
		RelationPayload $payload,
		MutationContext $context,
		PayloadNormalizer $normalizer,
	): void {
		$resolved = [];
		foreach ($payload->actions as $action) {
			if ($action instanceof BasicRelationAction) {
				array_push($resolved, ...$this->coerceBasicActions($context, $action));

				continue;
			}

			$resolved[] = $action;
		}

		$payload->actions = $resolved;

		foreach ($payload->actions as $action) {
			$this->resolvePayloadAction($context, $action, $normalizer);
		}
	}

	/**
	 * @return list<RelationAction>
	 */
	abstract protected function coerceBasicActions(MutationContext $context, BasicRelationAction $basic): array;

	abstract protected function resolvePayloadAction(
		MutationContext $context,
		RelationAction $action,
		PayloadNormalizer $normalizer,
	): void;
}
