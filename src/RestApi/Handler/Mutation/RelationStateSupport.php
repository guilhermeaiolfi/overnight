<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\MutationStateInterface;

trait RelationStateSupport
{
	protected function getPrimaryKeyValueFromState(
		MutationStateInterface $state,
		bool $requireReady = true
	): ?PrimaryKeyValue {
		return $state->getPrimaryKeyValue($requireReady);
	}
}
