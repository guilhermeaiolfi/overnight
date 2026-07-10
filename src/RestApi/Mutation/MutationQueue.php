<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Event\RelationConnected;
use ON\RestApi\Event\RelationConnecting;
use ON\RestApi\Event\RelationDisconnected;
use ON\RestApi\Event\RelationDisconnecting;
use ON\RestApi\Handler\RelationMutationHandlerInterface;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Payload\Action\ConnectAction;
use ON\RestApi\Payload\Action\DisconnectAction;
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

	public function queueDelete(
		CollectionInterface $collection,
		FilterNode $criteria,
		?MutationStateInterface $state = null,
	): MutationDeleteTaskInterface {
		$command = new DeleteCommand($collection, $criteria, $state);
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
				PrimaryKeyCriteria::build($node->collection, $node->state->getPrimaryKeyValue(false)),
				$node->state,
			),
			default => null,
		};
	}

	public function fill(
		MutationNode $node,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		bool $dispatchEvents
	): MutationTaskInterface|MutationDeleteTaskInterface|null {
		$inheritNestedAuthorization = false;

		return $this->fillNode($node, $dispatcher, $afterHooksTx, $node->state, $dispatchEvents, $inheritNestedAuthorization);
	}

	private function fillNode(
		MutationNode $node,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		MutationStateInterface $rootState,
		bool $dispatchEvents,
		bool &$inheritNestedAuthorization
	): MutationTaskInterface|MutationDeleteTaskInterface|null {
		if ($dispatchEvents) {
			$this->dispatchBeforeNodeHook($dispatcher, $node, $rootState, $inheritNestedAuthorization);
		}

		foreach ($node->relations as $relation) {
			foreach (self::CHILD_ACTIONS as $action) {
				foreach ($relation->children[$action] as $child) {
					$this->fillNode($child, $dispatcher, $afterHooksTx, $rootState, $dispatchEvents, $inheritNestedAuthorization);
				}
			}

			if ($dispatchEvents) {
				$this->dispatchBeforeRelationHooks($dispatcher, $relation, $rootState);
			}

			if ($relation->handler instanceof RelationMutationHandlerInterface) {
				$relation->handler->applyRelation($this, $relation->state, $relation->payload, $relation->children);
			}

			if ($dispatchEvents) {
				$this->scheduleAfterRelationHooks($afterHooksTx, $relation, $rootState);
			}
		}

		$task = $this->queueNode($node);
		if ($dispatchEvents) {
			$this->scheduleAfterNodeHook($afterHooksTx, $node, $task, $rootState);
		}

		return $task;
	}

	private function dispatchBeforeNodeHook(
		RestHookDispatcher $dispatcher,
		MutationNode $node,
		MutationStateInterface $rootState,
		bool &$inheritNestedAuthorization
	): void {
		$id = $node->operation !== 'create'
			? $node->state->getPrimaryKeyValue()
			: null;
		$event = match ($node->operation) {
			'create' => new ItemCreating($node, $this, $node->path, $rootState),
			'update' => $id === null ? null : new ItemUpdating($node, $id, $this, $node->path, $rootState),
			'delete' => $id === null ? null : new ItemDeleting($node, $id, $this, $node->path, $rootState),
			default => null,
		};
		if ($event === null) {
			return;
		}

		$inherit = $inheritNestedAuthorization && $event->getPath() !== [];
		$this->dispatchMutationHook($dispatcher, $node->operation . '.before', $event, $inherit);

		if ($event->getPath() === [] && $event->shouldInheritAuthToNested()) {
			$inheritNestedAuthorization = true;
		}
	}

	private function dispatchBeforeRelationHooks(
		RestHookDispatcher $dispatcher,
		RelationNode $relation,
		MutationStateInterface $rootState
	): void {
		foreach ($relation->payload->actions as $action) {
			match (true) {
				$action instanceof ConnectAction && $action->target !== null => $this->dispatchMutationHook(
					$dispatcher,
					'relation.connect.before',
					new RelationConnecting($relation, $action->target, $relation->path, $rootState, $this),
					false,
					false
				),
				$action instanceof DisconnectAction && $action->target !== null => $this->dispatchMutationHook(
					$dispatcher,
					'relation.disconnect.before',
					new RelationDisconnecting($relation, $action->target, $relation->path, $rootState, $this),
					false,
					false
				),
				default => null,
			};
		}
	}

	private function dispatchMutationHook(
		RestHookDispatcher $dispatcher,
		string $slot,
		object $event,
		bool $inheritNestedAuthorization = false,
		bool $assertAuthorization = true
	): object {
		if ($inheritNestedAuthorization && $event instanceof AuthorizationAwareEventInterface) {
			$event->inheritNestedAuthorization();
			if ($event->getAuthState() === AuthState::Pending) {
				$event->allow();
			}
		}

		return $dispatcher->dispatch($event->getCollection(), $slot, $event, $assertAuthorization);
	}

	private function scheduleAfterRelationHooks(
		RestHookTransaction $afterHooksTx,
		RelationNode $relation,
		MutationStateInterface $rootState
	): void {
		foreach ($relation->payload->actions as $action) {
			match (true) {
				$action instanceof ConnectAction && $action->target !== null => $afterHooksTx->schedule(
					'relation.connect.after',
					new RelationConnected($relation, $action->target, $relation->path, $rootState)
				),
				$action instanceof DisconnectAction && $action->target !== null => $afterHooksTx->schedule(
					'relation.disconnect.after',
					new RelationDisconnected($relation, $action->target, $relation->path, $rootState)
				),
				default => null,
			};
		}
	}

	private function scheduleAfterNodeHook(
		RestHookTransaction $afterHooksTx,
		MutationNode $node,
		MutationTaskInterface|MutationDeleteTaskInterface|null $task,
		MutationStateInterface $rootState
	): void {
		match ($node->operation) {
			'create' => $afterHooksTx->schedule('create.after', new ItemCreated($node->collection, $node->state, $node->path, $rootState)),
			'update' => $afterHooksTx->schedule('update.after', new ItemUpdated($node->collection, $node->state, $node->path, $rootState)),
			'delete' => $task instanceof MutationDeleteTaskInterface
				? $afterHooksTx->schedule('delete.after', static function () use ($node, $task, $rootState): ?ItemDeleted {
					if (! $task->getResult()) {
						return null;
					}

					if ($node->state->getRow() === null) {
						$node->state->markReady($node->state->getData());
					}

					return new ItemDeleted($node->collection, $node->state, true, $node->path, $rootState);
				})
				: null,
			default => null,
		};
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
