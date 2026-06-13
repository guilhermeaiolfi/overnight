<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class DeleteCommand extends AbstractMutationCommand
{
	private MutationStateInterface $state;

	public function __construct(
		private CollectionInterface $collection,
		private FilterNode $criteria,
		?MutationStateInterface $state = null,
	) {
		$this->state = $state ?? new MutationState($collection);
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function isReady(): bool
	{
		return $this->valuesReady($this->criteria);
	}

	public function execute(ItemRepositoryInterface $repository): void
	{
		$row = $repository->delete($this->collection, $this->resolveValue($this->criteria));
		if ($row !== null) {
			$this->state->markReady($row);
		}
	}
}
