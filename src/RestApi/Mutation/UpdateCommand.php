<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class UpdateCommand extends AbstractMutationCommand
{
	private MutationStateInterface $state;

	public function __construct(
		private CollectionInterface $collection,
		private array $criteria,
		private array|MutationStateInterface $input
	) {
		$this->state = $input instanceof MutationStateInterface
			? $input
			: new MutationState($collection, $input);
	}

	public function getTask(): MutationTaskInterface
	{
		return new MutationTask($this->state);
	}

	public function isReady(): bool
	{
		$input = $this->input instanceof MutationStateInterface ? $this->input->getData() : $this->input;

		return $this->valuesReady($this->criteria) && $this->valuesReady($input);
	}

	public function execute(ItemRepositoryInterface $repository): void
	{
		$input = $this->input instanceof MutationStateInterface ? $this->input->getData() : $this->input;
		$row = $repository->update(
			$this->collection,
			$this->resolveValue($this->criteria),
			$this->resolveValue($input)
		);
		$this->state->markReady($row ?? []);
	}
}
