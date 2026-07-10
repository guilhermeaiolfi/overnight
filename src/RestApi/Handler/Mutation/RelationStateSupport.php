<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Support\PrimaryKeyValue;

trait RelationStateSupport
{
	protected function getPrimaryKeyValueFromState(
		MutationStateInterface $state,
		bool $requireReady = true
	): ?PrimaryKeyValue {
		return $state->getPrimaryKeyValue($requireReady);
	}
}
