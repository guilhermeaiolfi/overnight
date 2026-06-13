<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Error\RestApiError;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class InsertCommand extends AbstractMutationCommand
{
	private MutationStateInterface $state;

	public function __construct(
		private MutationStateInterface $input,
		private bool $ignoreDuplicate = false
	) {
		$this->state = $input;
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function isReady(): bool
	{
		return $this->valuesReady($this->input->getData());
	}

	public function execute(ItemRepositoryInterface $repository): void
	{
		try {
			$row = $repository->create(
				$this->input->getCollection(),
				$this->resolveValue($this->input->getData())
			);
		} catch (RestApiError $error) {
			if ($this->ignoreDuplicate && $error->getErrorCode() === 'DUPLICATE') {
				$this->state->markReady([]);

				return;
			}

			throw $error;
		}

		$this->state->markReady($row ?? []);
	}
}
