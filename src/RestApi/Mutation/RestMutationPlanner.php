<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Resolver\DataSourceInterface;
use ON\RestApi\Resolver\Sql\Loader\LoaderFactory;
use ON\RestApi\Resolver\Sql\Loader\RootLoader;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestMutationPlanner
{
	/** @var list<object> */
	private array $afterEvents = [];

	/** @var list<array{0: CollectionInterface, 1: MutationStateInterface, 2: MutationDeleteTaskInterface, 3: array}> */
	private array $afterDeleteEvents = [];

	public function __construct(
		private DataSourceInterface $dataSource,
		private LoaderFactory $relationHandlers,
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
		?string $id = null,
		array $path = []
	): ?MutationTaskInterface {
		$prevented = null;
		$node = $this->planSaveNode($mode, $collection, $input, $id, $path, $prevented);
		if ($node === null) {
			return $prevented;
		}

		$result = RootLoader::persistMutation($node, $this->queue);
		$this->afterDeleteEvents = [...$this->afterDeleteEvents, ...$result['deleteEvents']];

		return $result['task'];
	}

	public function delete(CollectionInterface $collection, string $id, array $path = []): ?MutationDeleteTaskInterface
	{
		$prevented = null;
		$node = $this->planDeleteNode($collection, $id, $path, $prevented);
		if ($node === null) {
			return $prevented;
		}

		$result = RootLoader::persistMutation($node, $this->queue);
		$this->afterDeleteEvents = [...$this->afterDeleteEvents, ...$result['deleteEvents']];

		return $result['deleteTask'];
	}

	public function dispatchAfterEvents(): void
	{
		foreach ($this->afterEvents as $event) {
			$this->dispatchEvent($event);
		}

		foreach ($this->afterDeleteEvents as [$collection, $state, $task, $path]) {
			if (!$task->getResult()) {
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
		CollectionInterface $collection,
		array|MutationStateInterface $input,
		?string $id,
		array $path,
		?MutationTaskInterface &$prevented = null
	): ?array {
		$state = $input instanceof MutationStateInterface
			? $input
			: ($path === [] && $collection === $this->rootCollection
				? $this->rootState
				: new MutationState($collection, $input));
		if (!$input instanceof MutationStateInterface && $state === $this->rootState) {
			$state->setData($input);
		}

		if ($id === null && $mode !== 'create') {
			$id = $this->inputPrimaryKeyValue($collection, $state->getData());
		}
		$id = $id === null ? null : (string) $id;
		$operation = $mode;

		if ($mode === 'upsert') {
			$operation = $id !== null && $this->dataSource->get($collection, $id) !== null
				? 'update'
				: 'create';
		}

		if ($operation === 'update' && $id === null) {
			return null;
		}
		if ($operation === 'update') {
			$state->setValue($this->getPrimaryKeyName($collection), $id);
		}

		if ($this->dispatchEvents) {
			$event = $operation === 'create'
				? new ItemCreating($collection, $state, $this->queue, $path, $this->rootCollection, $this->rootState)
				: new ItemUpdating($collection, $id, $state, $this->queue, $path, $this->rootCollection, $this->rootState);
			$this->dispatchEvent($event);
			$this->assertAuthorized($event);

			if ($event->isDefaultPrevented()) {
				$prevented = new MutationTask(MutationState::fromRow($collection, $event->getPreventedResult()));
				return null;
			}
		}

		if ($operation === 'create') {
			$createId = $this->inputPrimaryKeyValue($collection, $state->getData());
			if (
				$createId !== null
				&& !$createId instanceof ValueRef
				&& $this->dataSource->get($collection, (string) $createId) !== null
			) {
				throw new RestApiError(
					"A record with this {$this->getPrimaryKeyName($collection)} already exists.",
					'DUPLICATE',
					$this->getPrimaryKeyName($collection),
					409
				);
			}
		}

		[$scalarInput, $relationInput] = $this->splitNodeInput($collection, $state->getData());
		$state->setData($scalarInput);
		$relations = [];

		foreach ($relationInput as $relationName => $input) {
			$relation = $this->planRelation($operation, $collection, $relationName, $input, $state, [...$path, $relationName]);
			if ($relation !== null) {
				$relations[$relationName] = $relation;
			}
		}

		$this->scheduleAfterSaveEvent($operation, $collection, $state, $path);

		return [
			'operation' => $operation,
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'relations' => $relations,
		];
	}

	private function planDeleteNode(
		CollectionInterface $collection,
		string $id,
		array $path,
		?MutationDeleteTaskInterface &$prevented = null
	): ?array {
		$state = new MutationState($collection, [$this->getPrimaryKeyName($collection) => $id]);

		if ($this->dispatchEvents) {
			$event = new ItemDeleting($collection, $id, $state, $this->queue, $path, $this->rootCollection, $this->rootState);
			$this->dispatchEvent($event);
			$this->assertAuthorized($event);

			if ($event->isDefaultPrevented()) {
				$prevented = new MutationDeleteTask(static fn(): bool => $event->getPreventedResult());
				return null;
			}
		}

		return [
			'operation' => 'delete',
			'collection' => $collection,
			'state' => $state,
			'path' => $path,
			'relations' => [],
		];
	}

	private function planRelation(
		string $operation,
		CollectionInterface $collection,
		string $relationName,
		mixed $relationInput,
		MutationStateInterface $state,
		array $path
	): ?array {
		$handler = $this->relationHandlers->mutation($collection, $relationName);
		if ($handler === null) {
			return null;
		}

		$payload = $handler->normalizePayload($operation, $relationInput, $state);
		$target = $handler->getTargetCollection();
		$children = [
			'create' => [],
			'update' => [],
			'delete' => [],
		];

		foreach ($payload['create'] ?? [] as $index => $item) {
			if (!is_array($item)) {
				continue;
			}

			$child = $this->planSaveNode('create', $target, $item, null, $this->relationChildPath($path, $relationInput, 'create', $index));
			if ($child !== null) {
				$children[$child['operation']][] = $child;
			}
		}

		foreach ($payload['update'] ?? [] as $index => $item) {
			if (!is_array($item)) {
				continue;
			}

			$id = $this->inputPrimaryKeyValue($target, $item);
			$child = $this->planSaveNode('upsert', $target, $item, $id === null ? null : (string) $id, $this->relationChildPath($path, $relationInput, 'update', $index));
			if ($child !== null) {
				$children[$child['operation']][] = $child;
			}
		}

		foreach ($payload['delete'] ?? [] as $index => $item) {
			$id = is_array($item) ? $this->inputPrimaryKeyValue($target, $item) : $item;
			if ($id === null) {
				continue;
			}

			$child = $this->planDeleteNode($target, (string) $id, $this->relationChildPath($path, $relationInput, 'delete', $index));
			if ($child !== null) {
				$children['delete'][] = $child;
			}
		}

		return [
			'handler' => $handler,
			'payload' => $payload,
			'children' => $children,
		];
	}

	private function scheduleAfterSaveEvent(
		string $operation,
		CollectionInterface $collection,
		MutationStateInterface $state,
		array $path
	): void {
		if (!$this->dispatchEvents) {
			return;
		}

		$this->afterEvents[] = $operation === 'create'
			? new ItemCreated($collection, $state, $path, $this->rootCollection, $this->rootState)
			: new ItemUpdated($collection, $state, $path, $this->rootCollection, $this->rootState);
	}

	private function relationChildPath(array $path, mixed $rawInput, string $operation, int|string $index): array
	{
		if (is_array($rawInput) && $this->isAssociativeArray($rawInput) && array_key_exists($operation, $rawInput)) {
			return [...$path, $operation, $index];
		}

		return [...$path, $index];
	}

	/**
	 * @return array{0: array, 1: array}
	 */
	private function splitNodeInput(CollectionInterface $collection, array $input): array
	{
		$scalar = [];
		$relations = [];

		foreach ($input as $key => $value) {
			if ($collection->relations->has((string) $key)) {
				$relations[(string) $key] = $value;
				continue;
			}

			$scalar[$key] = $value;
		}

		return [$scalar, $relations];
	}

	private function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKey = $this->getPrimaryKeyName($collection);

		return array_key_exists($primaryKey, $input) ? $input[$primaryKey] : null;
	}

	private function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	private function getPrimaryKeyName(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getName();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getName();
		}

		return 'id';
	}

	private function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
	}

	private function assertAuthorized(object $event): void
	{
		if (! $event instanceof AuthorizationAwareEventInterface) {
			return;
		}

		match ($event->getAuthState()) {
			AuthState::Allowed => null,
			AuthState::Unauthenticated => throw RestApiError::unauthenticated(),
			AuthState::Forbidden, AuthState::Pending => throw RestApiError::forbidden(),
		};
	}
}
