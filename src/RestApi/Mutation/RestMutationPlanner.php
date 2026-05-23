<?php



declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;
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
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Handler\RelationMutationHandlerInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Support\AuthorizationGuard;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestMutationPlanner
{
	private const ACTIONS = ['create', 'update', 'delete', 'connect', 'disconnect'];

	private const CHILD_ACTIONS = ['create', 'update', 'delete'];



	/** @var list<object> */

	private array $afterEvents = [];



	/** @var list<array{0: CollectionInterface, 1: MutationStateInterface, 2: MutationDeleteTaskInterface, 3: array}> */

	private array $afterDeleteEvents = [];

	private bool $inheritNestedAuthorization = false;

	public function __construct(
		private SqlDataSource $dataSource,
		private HandlerFactory $handlers,
		private ?EventDispatcherInterface $eventDispatcher,
		private bool $dispatchEvents,
		private MutationQueue $queue,
		private CollectionInterface $rootCollection,
		private MutationStateInterface $rootState
	) {

	}

	/**

	 * @param 'create'|'update'|'upsert' $mode

	 */
	public function save(
		string $mode,
		CollectionInterface $collection,
		array|MutationStateInterface $input,
		PrimaryKeyValue|string|null $id = null,
		array $path = []
	): ?MutationTaskInterface {

		$plan = $this->planSave($mode, $collection, $input, $id, $path);

		if ($plan === null) {

			return null;

		}



		$result = $this->commit($plan);



		return $result instanceof MutationDeleteTaskInterface ? null : $result;

	}

	/**

	 * @param 'create'|'update'|'upsert' $mode

	 */
	public function planSave(
		string $mode,
		CollectionInterface $collection,
		array|MutationStateInterface $input,
		PrimaryKeyValue|string|null $id = null,
		array $path = []
	): ?MutationPlan {

		$state = $input instanceof MutationStateInterface ? $input : new MutationState($collection, $input);

		if ($path === [] && $collection === $this->rootCollection) {

			$state = $this->rootState;

			if (is_array($input)) {

				$state->setData($input);

			}

		}



		$root = $this->planSaveNode($mode, $state, $id, $path);



		return $root === null ? null : new MutationPlan($root);

	}

	public function delete(
		CollectionInterface $collection,
		PrimaryKeyValue|string $id,
		array $path = []
	): ?MutationDeleteTaskInterface {

		$result = $this->commit($this->planDelete($collection, $id, $path));

		return $result instanceof MutationDeleteTaskInterface ? $result : null;

	}

	public function planDelete(
		CollectionInterface $collection,
		PrimaryKeyValue|string $id,
		array $path = []
	): MutationPlan {

		$identity = $this->normalizeIdentity($collection, $id);

		$state = new MutationState($collection, $identity->values());



		return new MutationPlan(new MutationNode(
			operation: 'delete',
			collection: $collection,
			state: $state,
			path: $path,
		));

	}

	public function commit(MutationPlan $plan): MutationTaskInterface|MutationDeleteTaskInterface|null
	{
		$this->inheritNestedAuthorization = false;

		$prevented = $this->dispatchBeforeEvents($plan->root);

		if ($prevented !== null) {

			return $prevented;

		}



		$this->scheduleAfterEvents($plan->root);



		return $this->fillQueue($plan->root);

	}

	public function dispatchAfterEvents(): void
	{

		foreach ($this->afterEvents as $event) {

			$this->dispatchEvent($event);

		}



		foreach ($this->afterDeleteEvents as [$collection, $state, $task, $path]) {

			if (! $task->getResult()) {

				continue;

			}



			$state->markReady($state->getData());

			$this->dispatchEvent(new ItemDeleted($collection, $state, true, $path, $this->rootCollection, $this->rootState));

		}

	}

	/**

	 * @param 'create'|'update'|'upsert' $mode

	 */
	private function planSaveNode(
		string $mode,
		MutationStateInterface $state,
		PrimaryKeyValue|string|null $id,
		array $path
	): ?MutationNode {

		$collection = $state->getCollection();

		if ($id === null && $mode !== 'create') {

			$id = $this->getInputPrimaryKeyValue($collection, $state->getData());

		}

		$id = $id === null ? null : $this->normalizeIdentity($collection, $id);

		$operation = $this->resolveOperation($mode, $collection, $id);



		if ($operation === 'update' && $id === null) {

			return null;

		}

		if ($operation === 'update') {

			foreach ($id->values() as $fieldName => $value) {

				$state->setValue($fieldName, $value);

			}

		}



		$this->assertCreateIdAvailable($operation, $collection, $state);



		[$scalarInput, $relationInput] = MutationInput::splitNodeInput($collection, $state->getData());

		$state->setData($scalarInput);

		$relations = $this->planRelations($operation, $collection, $state, $relationInput, $path);



		return new MutationNode(
			operation: $operation,
			collection: $collection,
			state: $state,
			path: $path,
			relations: $relations,
		);

	}

	/**

	 * @return array<string, RelationNode>

	 */
	private function planRelations(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $relationInput,
		array $path
	): array {

		$relations = [];

		foreach ($relationInput as $relationName => $rawInput) {

			$handler = $this->handlers->mutation($collection, (string) $relationName);

			if ($handler === null) {

				continue;

			}



			$payload = $handler->normalizePayload($operation, $rawInput, $state);

			$relationPath = [...$path, $relationName];

			$children = $this->planRelationChildren($payload, $rawInput, $relationPath);



			$relations[$relationName] = new RelationNode(
				handler: $handler,
				payload: $payload,
				state: $state,
				path: $relationPath,
				children: $children,
			);

		}



		return $relations;

	}

	/**

	 * @return array{create: list<MutationNode>, update: list<MutationNode>, delete: list<MutationNode>}

	 */
	private function planRelationChildren(
		RelationMutationPayload $payload,
		mixed $rawInput,
		array $path
	): array {

		$children = [

			'create' => [],

			'update' => [],

			'delete' => [],

		];



		foreach (self::CHILD_ACTIONS as $action) {

			foreach ($payload->{$action} as $index => $intent) {

				$child = $this->planRelationChild($action, $intent, $rawInput, $path, $index);

				if ($child !== null) {

					$children[$child->operation][] = $child;

				}

			}

		}



		return $children;

	}

	private function planRelationChild(
		string $action,
		ChildIntent $intent,
		mixed $rawInput,
		array $path,
		int|string $index
	): ?MutationNode {

		$collection = $intent->collection;

		$item = $intent->data;

		$childPath = $this->relationChildPath($path, $rawInput, $action, $index);



		return match ($action) {

			'create' => $this->planSaveNode('create', new MutationState($collection, $item), null, $childPath),

			'update' => $this->planSaveNode(
				'upsert',
				new MutationState($collection, $item),
				$this->getInputPrimaryKeyValue($collection, $item),
				$childPath
			),

			'delete' => $this->planDeleteChild($collection, $item, $childPath),

			default => null,

		};

	}

	private function planDeleteChild(
		CollectionInterface $collection,
		array $item,
		array $path
	): ?MutationNode {

		$id = $this->getInputPrimaryKeyValue($collection, $item);

		if ($id === null) {

			return null;

		}



		$identity = $this->normalizeIdentity($collection, $id);



		return new MutationNode(
			operation: 'delete',
			collection: $collection,
			state: new MutationState($collection, $identity->values()),
			path: $path,
		);

	}

	private function dispatchBeforeEvents(MutationNode $node): MutationTaskInterface|MutationDeleteTaskInterface|null
	{
		$beforeEvent = $this->scheduleNodeLifecycle($node);

		if ($beforeEvent instanceof ItemCreating && $beforeEvent->isDefaultPrevented()) {

			return new MutationTask(MutationState::fromRow($node->collection, $beforeEvent->getPreventedResult()));

		}



		if ($beforeEvent instanceof ItemDeleting && $beforeEvent->isDefaultPrevented()) {

			return new MutationDeleteTask(static fn (): bool => $beforeEvent->getPreventedResult());

		}



		if (
			$node->path === []
			&& $beforeEvent instanceof AuthorizationAwareEventInterface
			&& $beforeEvent->shouldInheritAuthToNested()
		) {
			$this->inheritNestedAuthorization = true;
		}



		foreach ($node->relations as $relation) {

			foreach (self::CHILD_ACTIONS as $action) {

				foreach ($relation->children[$action] as $child) {

					$prevented = $this->dispatchBeforeEvents($child);

					if ($prevented !== null) {

						return $prevented;

					}

				}

			}



			$this->dispatchRelationBeforeEvents($relation);

		}



		return null;

	}

	private function scheduleAfterEvents(MutationNode $node): void
	{

		foreach ($node->relations as $relation) {

			foreach (self::CHILD_ACTIONS as $action) {

				foreach ($relation->children[$action] as $child) {

					$this->scheduleAfterEvents($child);

				}

			}



			$this->scheduleRelationAfterEvents($relation);

		}



		$this->scheduleNodeLifecycle($node, true);

	}

	private function fillQueue(MutationNode $node): MutationTaskInterface|MutationDeleteTaskInterface|null
	{

		foreach ($node->relations as $relation) {

			foreach (self::CHILD_ACTIONS as $action) {

				foreach ($relation->children[$action] as $child) {

					$this->fillQueue($child);

				}

			}



			if ($relation->handler instanceof RelationMutationHandlerInterface) {

				$relation->handler->applyRelation(
					$this->queue,
					$relation->state,
					$relation->payload,
					$relation->children
				);

			}

		}



		$task = $this->queueRow($node);

		$this->storeDeleteTask($node, $task);



		return $task;

	}

	private function queueRow(MutationNode $node): MutationTaskInterface|MutationDeleteTaskInterface|null
	{

		$collection = $node->collection;



		return match ($node->operation) {

			'create' => $this->queue->queueInsert($node->state),

			'update' => $this->queue->queueUpdate(
				$collection,
				PrimaryKeyCriteria::build($collection, $this->statePrimaryKeyValue($node->state)),
				$node->state
			),

			'delete' => $this->queue->queueDelete(
				$collection,
				PrimaryKeyCriteria::build($collection, $this->statePrimaryKeyValue($node->state))
			),

			default => null,

		};

	}

	private function statePrimaryKeyValue(MutationStateInterface $state): PrimaryKeyValue
	{

		$values = [];

		foreach ($state->getCollection()->getPrimaryKey()->getFieldNames() as $fieldName) {

			$values[$fieldName] = $state->getValue($fieldName);

		}



		return new PrimaryKeyValue($state->getCollection(), $values);

	}

	private function resolveOperation(string $mode, CollectionInterface $collection, ?PrimaryKeyValue $id): string
	{

		if ($mode !== 'upsert') {

			return $mode;

		}



		return $id !== null && $this->findRowByIdentity($collection, $id) !== null ? 'update' : 'create';

	}

	private function assertCreateIdAvailable(string $operation, CollectionInterface $collection, MutationStateInterface $state): void
	{

		if ($operation !== 'create') {

			return;

		}



		$createId = $this->getInputPrimaryKeyValue($collection, $state->getData());

		if (

			$createId !== null

			&& ! array_filter($createId->values(), static fn (mixed $value): bool => $value instanceof ValueRef)

			&& $this->findRowByIdentity($collection, $createId) !== null

		) {

			$primaryKey = implode(', ', $collection->getPrimaryKey()->getFieldNames());

			throw new RestApiError(
				"A record with this {$primaryKey} already exists.",
				'DUPLICATE',
				$collection->getPrimaryKey()->getFieldNames()[0] ?? null,
				409
			);

		}

	}

	private function scheduleNodeLifecycle(MutationNode $node, bool $after = false): object|null
	{

		return $this->scheduleLifecycleEvent(
			$node->operation,
			$node->collection,
			$node->state,
			$node->path,
			$after,
			id: $node->operation !== 'create'

				? $this->getPrimaryKeyValueFromState($node->state)?->toUrlId()

				: null
		);

	}

	private function dispatchRelationBeforeEvents(RelationNode $relation): void
	{

		foreach (self::ACTIONS as $operation) {

			if (! in_array($operation, ['connect', 'disconnect'], true)) {

				continue;

			}



			foreach ($relation->payload->{$operation} as $index => $target) {

				$linkTarget = $target instanceof LinkIntent ? $target->target : $target;

				$path = [...$relation->path, $operation, $index];

				$this->scheduleLifecycleEvent(
					$operation,
					$relation->state->getCollection(),
					$relation->state,
					$path,
					false,
					$relation->handler->getRelationName(),
					$relation->handler->getTargetCollection(),
					$linkTarget
				);

			}

		}

	}

	private function scheduleRelationAfterEvents(RelationNode $relation): void
	{

		foreach (self::ACTIONS as $operation) {

			if (! in_array($operation, ['connect', 'disconnect'], true)) {

				continue;

			}



			foreach ($relation->payload->{$operation} as $index => $target) {

				$linkTarget = $target instanceof LinkIntent ? $target->target : $target;

				$path = [...$relation->path, $operation, $index];

				$this->scheduleLifecycleEvent(
					$operation,
					$relation->state->getCollection(),
					$relation->state,
					$path,
					true,
					$relation->handler->getRelationName(),
					$relation->handler->getTargetCollection(),
					$linkTarget
				);

			}

		}

	}

	private function scheduleLifecycleEvent(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $path,
		bool $after = false,
		?string $relationName = null,
		?CollectionInterface $targetCollection = null,
		mixed $target = null,
		?string $id = null
	): object|null {

		if (! $this->dispatchEvents) {

			return null;

		}



		$event = match ($operation) {

			'create' => $after

				? new ItemCreated($collection, $state, $path, $this->rootCollection, $this->rootState)

				: new ItemCreating($collection, $state, $this->queue, $path, $this->rootCollection, $this->rootState),

			'update' => $after

				? new ItemUpdated($collection, $state, $path, $this->rootCollection, $this->rootState)

				: new ItemUpdating($collection, (string) $id, $state, $this->queue, $path, $this->rootCollection, $this->rootState),

			'delete' => $after ? null : new ItemDeleting($collection, (string) $id, $state, $this->queue, $path, $this->rootCollection, $this->rootState),

			'connect' => $after
				? new RelationConnected($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState)
				: new RelationConnecting($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState, $this->queue),
			'disconnect' => $after
				? new RelationDisconnected($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState)
				: new RelationDisconnecting($collection, (string) $relationName, $targetCollection, $state, $target, $path, $this->rootCollection, $this->rootState, $this->queue),

			default => null,

		};



		if ($event === null) {

			return null;

		}



		if ($after) {

			$this->afterEvents[] = $event;

			return $event;

		}

		if (
			$this->inheritNestedAuthorization
			&& $path !== []
			&& $event instanceof AuthorizationAwareEventInterface
		) {
			$event->inheritNestedAuthorization();

			if ($event->getAuthState() === AuthState::Pending) {
				$event->allow();
			}
		}



		$this->dispatchEvent($event);

		AuthorizationGuard::assert($event);



		return $event;

	}

	private function relationChildPath(array $path, mixed $rawInput, string $operation, int|string $index): array
	{

		if (is_array($rawInput) && MutationInput::isAssociativeArray($rawInput) && array_key_exists($operation, $rawInput)) {

			return [...$path, $operation, $index];

		}



		if ($operation === 'delete') {

			return [...$path, 'delete', $index];

		}



		return [...$path, $index];

	}

	private function storeDeleteTask(MutationNode $node, MutationTaskInterface|MutationDeleteTaskInterface|null $task): void
	{

		if ($node->operation !== 'delete' || ! $task instanceof MutationDeleteTaskInterface) {

			return;

		}



		$this->afterDeleteEvents[] = [$node->collection, $node->state, $task, $node->path];

	}

	private function lastDeleteTask(): ?MutationDeleteTaskInterface
	{

		if ($this->afterDeleteEvents === []) {

			return null;

		}



		return $this->afterDeleteEvents[array_key_last($this->afterDeleteEvents)][2];

	}

	private function getInputPrimaryKeyValue(CollectionInterface $collection, array $input): ?PrimaryKeyValue
	{

		return $collection->getPrimaryKey()->extractFromInput($input);

	}

	private function normalizeIdentity(CollectionInterface $collection, PrimaryKeyValue|string $identity): PrimaryKeyValue
	{

		return PrimaryKeyCriteria::normalize($collection, $identity);

	}

	private function dispatchEvent(object $event): void
	{

		$this->eventDispatcher?->dispatch($event);

	}

	private function findRowByIdentity(CollectionInterface $collection, PrimaryKeyValue $identity): ?array
	{

		$fieldNames = [];

		foreach ($collection->fields as $fieldName => $field) {

			if (! $field->isHidden()) {

				$fieldNames[] = (string) $fieldName;

			}

		}



		foreach ($collection->getPrimaryKey()->getFieldNames() as $fieldName) {

			if (! in_array($fieldName, $fieldNames, true)) {

				$fieldNames[] = $fieldName;

			}

		}



		$query = $this->dataSource->select($collection, $fieldNames);

		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);

		$query->limit(1);



		return $this->dataSource->fetchOne($query);

	}

	private function getPrimaryKeyValueFromState(MutationStateInterface $state): ?PrimaryKeyValue
	{

		$values = [];

		foreach ($state->getCollection()->getPrimaryKey()->getFieldNames() as $fieldName) {

			$value = $state->getValue($fieldName);

			if ($value instanceof ValueRef && ! $value->isReady()) {

				return null;

			}



			$values[$fieldName] = $state->resolveValue($value);

		}



		return new PrimaryKeyValue($state->getCollection(), $values);

	}
}
