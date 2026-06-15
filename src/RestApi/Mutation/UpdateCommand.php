<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class UpdateCommand extends AbstractMutationCommand
{
	private NodeStateInterface $state;

	public function __construct(
		private CollectionInterface $collection,
		private FilterNode $criteria,
		private array|NodeStateInterface $input
	) {
		$this->state = $input instanceof NodeStateInterface
			? $input
			: new NodeState($collection, $input);
	}

	public function getState(): NodeStateInterface
	{
		return $this->state;
	}

	public function isReady(): bool
	{
		$input = $this->input instanceof NodeStateInterface ? $this->input->getData() : $this->input;

		return $this->valuesReady($this->criteria) && $this->valuesReady($input);
	}

	public function execute(ItemRepositoryInterface $repository): void
	{
		$input = $this->input instanceof NodeStateInterface ? $this->input->getData() : $this->input;
		$row = $repository->update(
			$this->collection,
			$this->resolveValue($this->criteria),
			$this->resolveValue($input)
		);
		if ($row !== null) {
			$this->state->markReady($row);
		}
	}
}
