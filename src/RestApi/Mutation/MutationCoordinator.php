<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\Mapper\Exception\ConversionException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Mutation\Event\MutationEventPlan;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Mutation\Payload\ToManyExplicitMutation;
use ON\RestApi\Mutation\Payload\ToManyImplicitMutation;
use ON\RestApi\Mutation\Payload\ToOneMutation;
use ON\RestApi\Payload\MutationInputMerger;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKey;
use ON\Validation\CollectionValidator;
use ON\Validation\ValidationFailedException;
use stdClass;
use Throwable;

/**
 * Directus payload → binder → hooks → Session sync/flush → reload.
 *
 * Batch operations share one Session and one flush (one transaction when the
 * command executor is transactional).
 */
final class MutationCoordinator
{
	private ?CollectionValidator $validator = null;

	public function __construct(
		private readonly SessionFactory $sessions,
		private readonly DirectusMutationBinder $binder,
		private readonly DirectusPayloadParser $parser,
		private readonly ItemRepositoryInterface $items,
		private readonly RestHookDispatcher $hooks,
		private readonly DataRuntime $runtime,
		private readonly ?RestApiConfig $config = null,
	) {
	}

	public function create(
		CollectionInterface $collection,
		array $input,
		array $files = [],
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
		?callable $prepare = null,
		string $inputRepresentation = PhpRepresentation::class,
	): array {
		return $this->write(
			'create',
			$collection,
			[['input' => $input, 'files' => $files]],
			$dispatchEvents,
			$output,
			$prepare,
			$inputRepresentation,
		)[0];
	}

	public function update(
		CollectionInterface $collection,
		Key|string|int $identity,
		array $input,
		array $files = [],
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
		?callable $prepare = null,
		string $inputRepresentation = PhpRepresentation::class,
	): array {
		return $this->write(
			'update',
			$collection,
			[['identity' => $identity, 'input' => $input, 'files' => $files]],
			$dispatchEvents,
			$output,
			$prepare,
			$inputRepresentation,
		)[0];
	}

	public function delete(
		CollectionInterface $collection,
		Key|string|int $identity,
		bool $dispatchEvents = true,
		?callable $checkPrecondition = null,
	): bool {
		return $this->batchDelete($collection, [$identity], $dispatchEvents, $checkPrecondition);
	}

	/**
	 * @param list<array<string, mixed>> $inputs
	 * @param class-string $output
	 */
	public function batchCreate(
		CollectionInterface $collection,
		array $inputs,
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
		?callable $prepare = null,
		string $inputRepresentation = PhpRepresentation::class,
	): array {
		$items = array_map(static fn (array $input): array => ['input' => $input], $inputs);

		return $this->write('create', $collection, $items, $dispatchEvents, $output, $prepare, $inputRepresentation);
	}

	/**
	 * @param list<array{identity: Key|string|int, input: array<string, mixed>}> $items
	 * @param class-string $output
	 */
	public function batchUpdate(
		CollectionInterface $collection,
		array $items,
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
		?callable $prepare = null,
		string $inputRepresentation = PhpRepresentation::class,
	): array {
		return $this->write('update', $collection, $items, $dispatchEvents, $output, $prepare, $inputRepresentation);
	}

	/**
	 * @param list<Key|string|int> $identities
	 */
	public function batchDelete(
		CollectionInterface $collection,
		array $identities,
		bool $dispatchEvents = true,
		?callable $checkPrecondition = null,
	): bool {
		if ($identities === []) {
			return true;
		}

		$requests = [];
		foreach ($identities as $identity) {
			$key = PrimaryKey::of($collection)->getValue($identity);
			$event = $dispatchEvents ? $this->authorizeRoot('delete', $collection, [], $key) : null;
			if ($event?->isDefaultPrevented()) {
				if ($event->getPreventResult() === null) {
					throw RestApiError::mutationPrevented();
				}

				return true;
			}

			$requests[] = [$key, $event];
		}

		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$roots = [];
		foreach ($requests as [$key, $event]) {
			if ($checkPrecondition !== null) {
				$checkPrecondition($key);
			}

			$roots[] = $this->binder->bindDelete($session, $collection, $key, $event?->getState());
		}

		$this->flush($session, $roots, $collection, $dispatchEvents);

		return true;
	}

	/**
	 * @param list<array{identity?: Key|string|int, input: array<string, mixed>, files?: array<string, mixed>}> $items
	 * @param class-string $output
	 * @param (callable(array, ?Key, array): array)|null $prepare
	 * @return list<array<string, mixed>>
	 */
	private function write(
		string $operation,
		CollectionInterface $collection,
		array $items,
		bool $dispatchEvents,
		string $output,
		?callable $prepare,
		string $inputRepresentation,
	): array {
		if ($items === []) {
			return [];
		}

		$requests = [];
		$primaryKey = PrimaryKey::of($collection);
		foreach ($items as $item) {
			$identity = isset($item['identity']) ? $primaryKey->getValue($item['identity']) : null;
			$input = $item['input'];
			if (($item['files'] ?? []) !== []) {
				$input = (new MutationInputMerger())->mergeFiles($collection, $input, $item['files']);
			}
			if ($identity !== null) {
				$input = $primaryKey->withoutFields($input);
			}
			[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);

			try {
				$typedScalars = $this->binder->normalizeRootValues($collection, $scalars, $inputRepresentation);
			} catch (ConversionException $exception) {
				throw RestApiError::validationFailed(['_root' => [$exception->getMessage()]]);
			}

			$event = $dispatchEvents ? $this->authorizeRoot($operation, $collection, $typedScalars, $identity) : null;
			if ($event?->isDefaultPrevented()) {
				$result = $event->getPreventResult();
				if (count($items) === 1 && $result !== null) {
					return [$result];
				}

				throw RestApiError::mutationPrevented();
			}

			if ($event !== null) {
				foreach ($event->getState()->getData() as $field => $value) {
					if ($collection->hasField((string) $field)) {
						$typedScalars[$field] = $value;
					}
				}
			}

			$requests[] = [$identity, $typedScalars + $relations, $input, $event];
		}

		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$roots = [];
		$deferredValidation = [];
		foreach ($requests as [$identity, $input, $validationInput, $event]) {
			$mutation = $this->parser->parse($collection, $input);
			$this->assertVisibleRelations($mutation->relations);
			if ($prepare !== null) {
				$mutation = $this->parser->parse($collection, $prepare($input, $identity, $validationInput));
			}

			$bound = $operation === 'create'
				? $this->binder->bindCreate($session, $collection, $mutation, $event?->getState(), valuesArePhp: true)
				: $this->binder->bindUpdate($session, $collection, $mutation, $identity, $event?->getState(), valuesArePhp: true);
			$roots[] = $bound;

			$nodes = MutationEventPlan::fromBound($bound)->before;
			if ($dispatchEvents) {
				foreach ($nodes as $node) {
					if (! $node->isRoot()) {
						$this->dispatchBefore($node, $bound, $event?->shouldInheritAuthToNested() ?? false);
					}
				}
				foreach ($nodes as $node) {
					$this->reapplyHookMutations($session, $node);
				}
			}
			foreach ($nodes as $node) {
				if ($node->isRoot() || $node->operation === 'delete') {
					continue;
				}
				if (! $this->validateNested($node, allowPendingKeys: true)) {
					$deferredValidation[] = $node;
				}
			}
		}

		return $this->flush($session, $roots, $collection, $dispatchEvents, $output, $deferredValidation);
	}

	private function authorizeRoot(
		string $operation,
		CollectionInterface $collection,
		array $values,
		?Key $identity,
	): ItemCreating|ItemDeleting {
		$creating = $operation === 'create';
		$state = new BoundItemState(
			$collection,
			new stdClass(),
			$values,
			ready: ! $creating,
			identityMutable: $creating,
			creating: $creating,
			identity: $identity,
			loadRow: $identity === null ? null : fn () => $this->items->findByIdentity($collection, $identity),
			useRowAsData: $operation === 'delete',
		);
		$event = match ($operation) {
			'create' => new ItemCreating($collection, $state),
			'update' => new ItemUpdating($collection, $state, $identity),
			'delete' => new ItemDeleting($collection, $state, $identity),
		};
		$this->hooks->dispatch($collection, $operation . '.before', $event);

		return $event;
	}

	private function assertVisibleRelations(array $relations): void
	{
		foreach ($relations as $relation) {
			if ($relation->relation()->getCollection()->isHidden()) {
				throw RestApiError::invalidField($relation->path()->toString());
			}

			$items = match (true) {
				$relation instanceof ToOneMutation => $relation->item === null ? [] : [$relation->item],
				$relation instanceof ToManyImplicitMutation => $relation->items,
				$relation instanceof ToManyExplicitMutation => [...$relation->create, ...$relation->update],
				default => [],
			};
			foreach ($items as $item) {
				$this->assertVisibleRelations($item->relations);
			}
		}
	}

	private function validateNested(BoundMutation $bound, bool $allowPendingKeys = false): bool
	{
		$this->validator ??= new CollectionValidator(
			$this->config?->get('validationMessages', []) ?? [],
			$this->config?->get('validationLang', 'en') ?? 'en',
		);

		try {
			$input = $bound->state->getData();
			foreach ($input as $field => $value) {
				if (array_key_exists($field, $bound->submittedValues) && $value === $bound->mutation->values[$field]) {
					$input[$field] = $bound->submittedValues[$field];
				}
				if (is_object($input[$field])) {
					$wire = map([$field => $input[$field]])->args($bound->collection)
						->from(PhpRepresentation::class)->as(WireRepresentation::class)->to([]);
					$input[$field] = $wire[$field] ?? null;
				}
			}
			$pendingFields = [];
			foreach ($bound->parentKeys as $field => $key) {
				if ($allowPendingKeys && ! $key->isResolved()) {
					unset($input[$field]);
					$pendingFields[] = $field;
				} else {
					$input[$field] = $key->resolve();
				}
			}

			return $this->validator->validateAvailable(
				$bound->collection,
				$input,
				$pendingFields,
				partial: $bound->operation === 'update',
			);
		} catch (ValidationFailedException $exception) {
			$errors = [];
			foreach ($exception->getErrors() as $field => $messages) {
				$errors[$bound->path->append($field)->toString()] = $messages;
			}

			throw RestApiError::validationFailed($errors);
		}
	}

	/**
	 * @param list<BoundMutation> $roots
	 * @param list<BoundMutation> $deferredValidation
	 * @param class-string $output
	 * @return list<array<string, mixed>>
	 */
	private function flush(
		Session $session,
		array $roots,
		CollectionInterface $collection,
		bool $dispatchEvents,
		?string $output = null,
		array $deferredValidation = [],
	): array {
		foreach ($roots as $bound) {
			$session->sync($bound->representation);
		}

		try {
			if ($deferredValidation === []) {
				$session->flush();
			} else {
				// Generated parent keys become available during flush. Keep the writes
				// rollbackable until the child rules have checked their actual values.
				$executor = $this->runtime->getCommandExecutor();
				assert($executor instanceof TransactionalCommandExecutorInterface);
				$executor->transaction(function () use ($session, $deferredValidation): void {
					$session->flush();
					foreach ($deferredValidation as $node) {
						$this->validateNested($node);
					}
				});
			}
		} catch (Throwable $exception) {
			throw RestApiError::databaseConstraint($exception, $collection) ?? $exception;
		}

		$results = [];
		$reloadedRows = [];
		$afterHooks = $this->hooks->start();
		foreach ($roots as $bound) {
			if ($output !== null) {
				$row = $this->reload($session, $bound, $reloadedRows);
				$results[] = map($row)
					->args($bound->collection)
					->from(PhpRepresentation::class)
					->as($output)
					->to([]);
			}

			if ($dispatchEvents) {
				$this->scheduleAfter($afterHooks, $session, MutationEventPlan::fromBound($bound), $bound, $reloadedRows);
			}
		}
		if ($dispatchEvents) {
			$afterHooks->flush();
		}

		return $results;
	}

	private function dispatchBefore(BoundMutation $bound, BoundMutation $root, bool $inheritNested): void
	{
		$event = match ($bound->operation) {
			'create' => new ItemCreating($bound->collection, $bound->state, $bound->path->toArray(), $root->state),
			'update' => new ItemUpdating(
				$bound->collection,
				$bound->state,
				$this->requireIdentity($bound),
				$bound->path->toArray(),
				$root->state,
			),
			'delete' => new ItemDeleting(
				$bound->collection,
				$bound->state,
				$this->requireIdentity($bound),
				$bound->path->toArray(),
				$root->state,
			),
			default => null,
		};
		if ($event === null) {
			return;
		}

		if ($inheritNested) {
			$event->inheritNestedAuthorization();
			if ($event->getAuthState() === AuthState::Pending) {
				$event->allow();
			}
		}

		$this->hooks->dispatch($bound->collection, $bound->operation . '.before', $event);
		if ($event->isDefaultPrevented()) {
			throw RestApiError::mutationPrevented();
		}
	}

	/** @param array<string, array<string, mixed>> $reloadedRows */
	private function scheduleAfter(
		RestHookTransaction $afterHooksTx,
		Session $session,
		MutationEventPlan $plan,
		BoundMutation $root,
		array &$reloadedRows,
	): void {
		foreach ($plan->after as $bound) {
			$slot = $bound->operation . '.after';
			$hooks = $bound->collection->metadata(RestHooks::METADATA_KEY) ?? [];
			if ($bound !== $root && isset($hooks[$slot]) && in_array($bound->operation, ['create', 'update'], true)) {
				$this->reload($session, $bound, $reloadedRows);
			}

			$afterHooksTx->schedule($slot, function () use ($bound, $root) {
				return match ($bound->operation) {
					'create' => new ItemCreated($bound->collection, $bound->state, $bound->path->toArray(), $root->state),
					'update' => new ItemUpdated($bound->collection, $bound->state, $bound->path->toArray(), $root->state),
					'delete' => new ItemDeleted($bound->collection, $bound->state, true, $bound->path->toArray(), $root->state),
					default => null,
				};
			});
		}
	}

	private function reapplyHookMutations(Session $session, BoundMutation $bound): void
	{
		$data = $bound->state->getData();
		if ($data === []) {
			return;
		}

		$bound->state->setData($data);

		$pendingFields = $bound->state->pullPendingHookFields();
		if ($pendingFields === []) {
			return;
		}

		$target = $bound->representation;
		$overlayFields = [];
		foreach ($pendingFields as $field) {
			if ($bound->collection->hasField($field)) {
				$overlayFields[] = $field;
			}
		}
		if ($overlayFields === []) {
			return;
		}

		// Already tracked (writable load or early create sync): patch RecordState directly.
		// Re-issuing create()/update() would adopt a second NEW record and leave the first
		// incomplete row to flush (NOT NULL on hook-added columns).
		$representationState = $session->getRepresentations()->get($target);
		if ($representationState instanceof RepresentationState) {
			$record = $representationState->getSingleRecord();
			if ($record instanceof RecordState) {
				foreach ($overlayFields as $field) {
					$record->setValue($field, $target->{$field} ?? null);
				}

				return;
			}
		}

		$fieldNames = $overlayFields;
		$query = $this->runtime->query($bound->collection);
		$refs = [];
		foreach ($fieldNames as $field) {
			$refs[] = $query->field($field);
		}
		$map = $query->select(...$refs)->projection();
		if ($bound->operation === 'create') {
			$session->create($target, $map);
		} else {
			$session->update($target, $map);
		}
		$session->sync($target);
	}

	/**
	 * @param array<string, array<string, mixed>> $reloadedRows
	 * @return array<string, mixed>
	 */
	private function reload(Session $session, BoundMutation $bound, array &$reloadedRows): array
	{
		$key = $this->resolveKeyAfterFlush($session, $bound);
		$hash = $key->getHash();
		$row = $reloadedRows[$hash] ?? null;
		if ($row === null) {
			$row = $this->items->findByIdentity($bound->collection, $key);
			if ($row === null) {
				throw RestApiError::notFound();
			}

			// Share scalar snapshots only. Mapped values can contain mutable objects or resources.
			if (array_filter($row, static fn (mixed $value): bool => $value !== null && ! is_scalar($value)) === []) {
				$reloadedRows[$hash] = $row;
			}
		}

		$bound->state->markReady($row + $key->getValues());

		return $row;
	}

	private function resolveKeyAfterFlush(Session $session, BoundMutation $bound): Key
	{
		if ($bound->rootRecord instanceof RecordState && $bound->rootRecord->hasKey()) {
			return $bound->rootRecord->getKey();
		}

		$representationState = $session->getRepresentations()->get($bound->representation);
		if ($representationState instanceof RepresentationState) {
			foreach ($representationState->getFieldItems() as $fieldItem) {
				$record = $fieldItem->getRecord();
				if (
					$record->getCollection()->getName() === $bound->collection->getName()
					&& $record->hasKey()
				) {
					return $record->getKey();
				}
			}
		}

		$record = null;
		$state = $session->getRepresentations()->get($bound->representation);
		if ($state instanceof RepresentationState) {
			$record = $state->getSingleRecord();
		}
		if ($record instanceof RecordState && $record->hasKey()) {
			return $record->getKey();
		}

		if ($bound->identity instanceof Key) {
			return $bound->identity;
		}

		$identity = $bound->state->getKey(false);
		if ($identity !== null) {
			return $identity;
		}

		throw RestApiError::notFound();
	}

	private function requireIdentity(BoundMutation $bound): Key
	{
		if ($bound->identity instanceof Key) {
			return $bound->identity;
		}

		$identity = $bound->state->getKey(false);
		if ($identity === null) {
			throw RestApiError::notFound();
		}

		return $identity;
	}
}
