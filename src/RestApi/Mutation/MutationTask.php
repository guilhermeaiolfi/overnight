<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

final class MutationTask implements MutationTaskInterface
{
	public function __construct(
		private MutationStateInterface $state
	) {
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function getRow(): ?array
	{
		return $this->state->getRow();
	}
}
