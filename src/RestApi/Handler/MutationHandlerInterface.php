<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationMutationPayload;

interface MutationHandlerInterface
{
	public function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source
	): RelationMutationPayload;
}
