<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;

interface MutationQueueInterface
{
	public function queueInsert(
		MutationStateInterface $state,
		bool $ignoreDuplicate = false
	): MutationTaskInterface;

	public function queueUpdate(
		CollectionInterface $collection,
		FilterNode $criteria,
		array|MutationStateInterface $input
	): MutationTaskInterface;

	public function queueDelete(
		CollectionInterface $collection,
		FilterNode $criteria
	): MutationDeleteTaskInterface;

	public function execute(ItemRepositoryInterface $repository): void;
}
