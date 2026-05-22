<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationDeleteTaskInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\MutationTaskInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;

interface MutationHandlerInterface
{
	public function mutationCollection(string $operation, mixed $item): CollectionInterface;

	public function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed;

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		SqlDataSource $dataSource
	): array;

	public function compileActions(
		MutationQueue $queue,
		MutationStateInterface $source,
		array $actions,
		array $children = []
	): MutationTaskInterface|MutationDeleteTaskInterface|null;
}
