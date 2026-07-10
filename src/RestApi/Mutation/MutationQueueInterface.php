<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Repository\ItemRepositoryInterface;

interface MutationQueueInterface
{
	public function queueInsert(
		MutationStateInterface $state,
		bool $ignoreDuplicate = false
	): MutationTaskInterface;

	public function queueUpdate(
		CollectionInterface $collection,
		array $criteria,
		array|MutationStateInterface $input
	): MutationTaskInterface;

	public function queueDelete(
		CollectionInterface $collection,
		array $criteria,
		?MutationStateInterface $state = null,
	): MutationDeleteTaskInterface;

	public function queueNode(MutationNode $node): MutationTaskInterface|MutationDeleteTaskInterface|null;

	public function fill(
		MutationNode $node,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		bool $dispatchEvents
	): MutationTaskInterface|MutationDeleteTaskInterface|null;

	public function execute(ItemRepositoryInterface $repository): void;
}
