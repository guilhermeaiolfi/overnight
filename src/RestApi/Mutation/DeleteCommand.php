<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Resolver\DataSourceInterface;

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
		return new MutationDeleteTask(fn(): bool => $this->result);
	}

	public function isReady(): bool
	{
		return $this->valuesReady($this->criteria);
	}

	public function execute(DataSourceInterface $dataSource): void
	{
		$this->result = $dataSource->delete($this->collection, $this->resolveValue($this->criteria));
	}
}
