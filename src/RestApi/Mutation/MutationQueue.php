<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use LogicException;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\RestEventManager;
use ON\RestApi\Handler\RelationMutationHandlerInterface;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKeyCriteria;

final class MutationQueue implements MutationQueueInterface
{
	private const CHILD_ACTIONS = ['create', 'update', 'delete'];

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

	public function queueNode(MutationNode $node): MutationTaskInterface|MutationDeleteTaskInterface|null
	{
		return match ($node->operation) {
			'create' => $this->queueInsert($node->state),
			'update' => $this->queueUpdate(
				$node->collection,
				PrimaryKeyCriteria::build($node->collection, $node->state->getPrimaryKeyValue(false)),
				$node->state
			),
			'delete' => $this->queueDelete(
				$node->collection,
				PrimaryKeyCriteria::build($node->collection, $node->state->getPrimaryKeyValue(false))
			),
			default => null,
		};
	}

	public function fill(
		MutationNode $node,
		RestEventManager $events,
		MutationStateInterface $rootState,
		bool $dispatchEvents
	): MutationTaskInterface|MutationDeleteTaskInterface|null {
		foreach ($node->relations as $relation) {
			foreach (self::CHILD_ACTIONS as $action) {
				foreach ($relation->children[$action] as $child) {
					$this->fill($child, $events, $rootState, $dispatchEvents);
				}
			}

			if ($relation->handler instanceof RelationMutationHandlerInterface) {
				$relation->handler->applyRelation($this, $relation->state, $relation->payload, $relation->children);
			}
		}

		$task = $this->queueNode($node);
		if ($dispatchEvents && $node->operation === 'delete' && $task instanceof MutationDeleteTaskInterface) {
			$events->scheduleAfterEvent(static function () use ($node, $task, $rootState): ?ItemDeleted {
				if (! $task->getResult()) {
					return null;
				}

				$node->state->markReady($node->state->getData());

				return new ItemDeleted($node->collection, $node->state, true, $node->path, $rootState);
			});
		}

		return $task;
	}

	public function execute(ItemRepositoryInterface $repository): void
	{
		while ($this->commands !== []) {
			$executed = false;

			foreach ($this->commands as $index => $command) {
				if (! $command->isReady()) {
					continue;
				}

				$command->execute($repository);
				unset($this->commands[$index]);
				$executed = true;
			}

			if (! $executed) {
				throw new LogicException('Unable to resolve mutation queue dependencies.');
			}
		}
	}
}
