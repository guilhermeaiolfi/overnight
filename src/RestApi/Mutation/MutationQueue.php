<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use LogicException;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Resolver\DataSourceInterface;

final class MutationQueue implements MutationQueueInterface
{
	/** @var list<MutationCommandInterface> */
	private array $commands = [];

	public function queueInsert(MutationStateInterface $state, bool $ignoreDuplicate = false): MutationTaskInterface
	{
		$command = new InsertCommand($state, $ignoreDuplicate);
		$this->commands[] = $command;

		return $command->getTask();
	}

	public function queueUpdate(
		CollectionInterface $collection,
		FilterNode $criteria,
		array|MutationStateInterface $input
	): MutationTaskInterface {
		$command = new UpdateCommand($collection, $criteria, $input);
		$this->commands[] = $command;

		return $command->getTask();
	}

	public function queueDelete(CollectionInterface $collection, FilterNode $criteria): MutationDeleteTaskInterface
	{
		$command = new DeleteCommand($collection, $criteria);
		$this->commands[] = $command;

		return $command->getTask();
	}

	public function execute(DataSourceInterface $dataSource): void
	{
		while ($this->commands !== []) {
			$executed = false;

			foreach ($this->commands as $index => $command) {
				if (! $command->isReady()) {
					continue;
				}

				$command->execute($dataSource);
				unset($this->commands[$index]);
				$executed = true;
			}

			if (! $executed) {
				throw new LogicException('Unable to resolve mutation queue dependencies.');
			}
		}
	}
}
