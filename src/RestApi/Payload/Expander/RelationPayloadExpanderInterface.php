<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Expander;

use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Payload\Action\BasicRelationAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\MutationContext;
use ON\RestApi\Payload\PayloadNormalizer;

interface RelationPayloadExpanderInterface
{
	/**
	 * @return list<RelationAction>
	 */
	public function expandBasic(
		MutationContext $context,
		BasicRelationAction $basic,
	): array;

	public function resolveAction(
		MutationContext $context,
		RelationAction $action,
		PayloadNormalizer $normalizer,
	): void;
}
