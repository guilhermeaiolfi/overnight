<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use LogicException;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
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
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKeyCriteria;

final class OperationQueue implements OperationQueueInterface
{
	private const CHILD_ACTIONS = ['create', 'update', 'delete'];

	/** @var list<MutationCommandInterface> */
	private array $commands = [];

	public function queueInsert(NodeStateInterface $state, bool $ignoreDuplicate = false): NodeStateInterface
	{
		$command = new InsertCommand($state, $ignoreDuplicate);
		$this->commands[] = $command;

		return $command->getState();
	}

	public function queueUpdate(
		CollectionInterface $collection,
		FilterNode $criteria,
		array|NodeStateInterface $input
	): NodeStateInterface {
		$command = new UpdateCommand($collection, $criteria, $input);
		$this->commands[] = $command;

		return $command->getState();
	}

	public function queueDelete(
		CollectionInterface $collection,
		FilterNode $criteria,
		?NodeStateInterface $state = null,
	): NodeStateInterface {
		$command = new DeleteCommand($collection, $criteria, $state);
		$this->commands[] = $command;

		return $command->getState();
	}

	public function queueNode(RecordNode $node): ?NodeStateInterface
	{
		if ($node->operation === 'update' && $node->currentData !== null && ! $node->hasScalarChanges()) {
			$node->state->markReady($node->currentData);

			return $node->state;
		}

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
		RecordStore $store,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		bool $dispatchEvents
	): ?NodeStateInterface {
		$inheritNestedAuthorization = false;
		$node = $store->root;

		return $this->fillNode($node, $dispatcher, $afterHooksTx, $node->state, $dispatchEvents, $inheritNestedAuthorization);
	}

	private function fillNode(
		RecordNode $node,
		RestHookDispatcher $dispatcher,
		RestHookTransaction $afterHooksTx,
		NodeStateInterface $rootState,
		bool $dispatchEvents,
		bool &$inheritNestedAuthorization
	): ?NodeStateInterface {
		if ($dispatchEvents) {
			$this->dispatchBeforeNodeHook($dispatcher, $node, $rootState, $inheritNestedAuthorization);
		}

			foreach ($node->relations as $relation) {
			foreach (self::CHILD_ACTIONS as $action) {
				foreach ($relation->childRecordsByOperation($action) as $child) {
					$this->fillNode($child, $dispatcher, $afterHooksTx, $rootState, $dispatchEvents, $inheritNestedAuthorization);
				}
			}

			if ($dispatchEvents) {
				$this->dispatchBeforeRelationHooks($dispatcher, $relation, $rootState);
			}

			if ($relation->handler instanceof RelationMutationHandlerInterface && $relation->state !== null) {
				$relation->handler->applyRelation($this, $relation->state, $relation);
			}

			if ($dispatchEvents) {
				$this->scheduleAfterRelationHooks($afterHooksTx, $relation, $rootState);
			}
		}

		$state = $this->queueNode($node);
		if ($dispatchEvents) {
			$this->scheduleAfterNodeHook($afterHooksTx, $node, $rootState);
		}

		return $state;
	}

	private function dispatchBeforeNodeHook(
		RestHookDispatcher $dispatcher,
		RecordNode $node,
		NodeStateInterface $rootState,
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
		$this->dispatchMutationHook($dispatcher, $event, $inherit);

		if ($event->getPath() === [] && $event->shouldInheritAuthToNested()) {
			$inheritNestedAuthorization = true;
		}
	}

	private function dispatchBeforeRelationHooks(
		RestHookDispatcher $dispatcher,
		RelationNode $relation,
		NodeStateInterface $rootState
	): void {
		foreach ($this->connectTargets($relation) as $target) {
			$this->dispatchMutationHook(
				$dispatcher,
				new RelationConnecting($relation, $target, $relation->path, $rootState, $this),
				false,
				false
			);
		}

		foreach ($this->disconnectTargets($relation) as $target) {
			$this->dispatchMutationHook(
				$dispatcher,
				new RelationDisconnecting($relation, $target, $relation->path, $rootState, $this),
				false,
				false
			);
		}
	}

	private function dispatchMutationHook(
		RestHookDispatcher $dispatcher,
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

		return $dispatcher->dispatch($event, $assertAuthorization);
	}

	private function scheduleAfterRelationHooks(
		RestHookTransaction $afterHooksTx,
		RelationNode $relation,
		NodeStateInterface $rootState
	): void {
		foreach ($this->connectTargets($relation) as $target) {
			$afterHooksTx->schedule(new RelationConnected($relation, $target, $relation->path, $rootState));
		}

		foreach ($this->disconnectTargets($relation) as $target) {
			$afterHooksTx->schedule(new RelationDisconnected($relation, $target, $relation->path, $rootState));
		}
	}

	private function connectTargets(RelationNode $relation): array
	{
		$targets = [];
		foreach ($relation->children as $child) {
			if (
				$child->relationIntent === 'desired'
				&& ! in_array($child->operation, ['create', 'update', 'delete'], true)
				&& $child->inputIdentity !== null
				&& ($child->currentIdentity === null || $child->currentIdentity->toUrlId() !== $child->inputIdentity->toUrlId())
			) {
				$targets[$child->inputIdentity->toUrlId()] = $child->inputIdentity;
			}
		}

		return array_values($targets);
	}

	private function disconnectTargets(RelationNode $relation): array
	{
		$targets = [];
		foreach ($relation->children as $child) {
			if ($child->relationIntent === 'omitted' && $child->currentIdentity !== null && ! in_array($child->operation, ['create', 'update', 'delete'], true)) {
				$targets[$child->currentIdentity->toUrlId()] = $child->currentIdentity;
			}
		}

		return array_values($targets);
	}

	private function scheduleAfterNodeHook(
		RestHookTransaction $afterHooksTx,
		RecordNode $node,
		NodeStateInterface $rootState
	): void {
		$afterHooksTx->schedule(static function () use ($node, $rootState): object|null {
			if ($node->state->getRow() === null) {
				return null;
			}

			return match ($node->operation) {
				'create' => new ItemCreated($node->collection, $node->state, $node->path, $rootState),
				'update' => new ItemUpdated($node->collection, $node->state, $node->path, $rootState),
				'delete' => new ItemDeleted($node->collection, $node->state, true, $node->path, $rootState),
				default => null,
			};
		});
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
				throw new LogicException('Unable to resolve mutation queue dependencies: ' . implode(', ', array_map(
					static fn(MutationCommandInterface $command): string => $command::class,
					$this->commands,
				)));
			}
		}
	}
}
