<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;

interface OperationQueueInterface
{
	public function queueInsert(
		NodeStateInterface $state,
		bool $ignoreDuplicate = false
	): NodeStateInterface;

	public function queueUpdate(
		CollectionInterface $collection,
		FilterNode $criteria,
		array|NodeStateInterface $input
	): NodeStateInterface;

	public function queueDelete(
		CollectionInterface $collection,
		FilterNode $criteria,
		?NodeStateInterface $state = null,
	): NodeStateInterface;

	public function queueNode(RecordNode $node): ?NodeStateInterface;

	public function fill(
		RecordStore $store,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		bool $dispatchEvents
	): ?NodeStateInterface;

	public function execute(ItemRepositoryInterface $repository): void;
}
