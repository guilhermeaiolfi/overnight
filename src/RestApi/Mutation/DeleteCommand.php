<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class DeleteCommand extends AbstractMutationCommand
{
	private bool $result = false;

	public function __construct(
		private CollectionInterface $collection,
		private FilterNode $criteria
	) {
	}

	public function getTask(): MutationDeleteTaskInterface
	{
		return new MutationDeleteTask(fn (): bool => $this->result);
	}

	public function isReady(): bool
	{
		return $this->valuesReady($this->criteria);
	}

	public function execute(ItemRepositoryInterface $repository): void
	{
		$this->result = $repository->delete($this->collection, $this->resolveValue($this->criteria));
	}
}
