<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;

interface MutationQueueInterface
{
	public function queueInsert(
		MutationStateInterface $state,
		bool $ignoreDuplicate = false
	): MutationStateInterface;

	public function queueUpdate(
		CollectionInterface $collection,
		FilterNode $criteria,
		array|MutationStateInterface $input
	): MutationStateInterface;

	public function queueDelete(
		CollectionInterface $collection,
		FilterNode $criteria,
		?MutationStateInterface $state = null,
	): MutationStateInterface;

	public function queueNode(MutationNode $node): ?MutationStateInterface;

	public function fill(
		MutationNode $node,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		bool $dispatchEvents
	): ?MutationStateInterface;

	public function execute(ItemRepositoryInterface $repository): void;
}
