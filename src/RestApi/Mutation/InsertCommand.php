<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\DataSourceInterface;

final class InsertCommand extends AbstractMutationCommand
{
	private MutationStateInterface $state;

	public function __construct(
		private MutationStateInterface $input,
		private bool $ignoreDuplicate = false
	) {
		$this->state = $input;
	}

	public function getTask(): MutationTaskInterface
	{
		return new MutationTask($this->state);
	}

	public function isReady(): bool
	{
		return $this->valuesReady($this->input->getData());
	}

	public function execute(DataSourceInterface $dataSource): void
	{
		try {
			$row = $dataSource->create($this->input->getCollection(), $this->resolveValue($this->input->getData()));
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
