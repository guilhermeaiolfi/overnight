<?php

declare(strict_types=1);

namespace ON\RestApi\Handler\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\NodeStateInterface;

trait RelationStateSupport
{
	protected function getPrimaryKeyValueFromState(
		NodeStateInterface $state,
		bool $requireReady = true
	): ?PrimaryKeyValue {
		return $state->getPrimaryKeyValue($requireReady);
	}
}
