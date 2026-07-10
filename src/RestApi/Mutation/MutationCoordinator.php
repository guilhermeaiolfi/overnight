<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Hook\RestHookTransaction;
use ON\RestApi\Mutation\Event\MutationEventPlan;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKeyValue;
use Throwable;

/**
 * Directus payload → binder → hooks → Session sync/flush → reload.
 *
 * Batch operations share one Session and one flush (one transaction when the
 * command executor is transactional).
 */
final class MutationCoordinator
{
	public function __construct(
		private readonly SessionFactory $sessions,
		private readonly DirectusMutationBinder $binder,
		private readonly DirectusPayloadParser $parser,
		private readonly ItemRepositoryInterface $items,
		private readonly RestHookDispatcher $hooks,
		private readonly DataRuntime $runtime,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param class-string $output
	 * @return array<string, mixed>
	 */
	public function create(
		CollectionInterface $collection,
		array $input,
		array $files = [],
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$mutation = $this->parser->parse($collection, $input, $files);
		$bound = $this->binder->bindCreate($session, $collection, $mutation);

		return $this->executeWrite($session, [$bound], $dispatchEvents, $output)[0];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param class-string $output
	 * @return array<string, mixed>
	 */
	public function update(
		CollectionInterface $collection,
		PrimaryKeyValue|string|int $identity,
		array $input,
		array $files = [],
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$mutation = $this->parser->parse($collection, $input, $files);
		$bound = $this->binder->bindUpdate($session, $collection, $mutation, $identity);

		return $this->executeWrite($session, [$bound], $dispatchEvents, $output)[0];
	}

	public function delete(
		CollectionInterface $collection,
		PrimaryKeyValue|string|int $identity,
		bool $dispatchEvents = true,
	): bool {
		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$bound = $this->binder->bindDelete($session, $collection, $identity);
		$this->executeDelete($session, [$bound], $dispatchEvents);

		return true;
	}

	/**
	 * One request → one Session → bind all roots → before-events → one flush → after-events.
	 *
	 * @param list<array<string, mixed>> $inputs
	 * @param class-string $output
	 * @return list<array<string, mixed>>
	 */
	public function batchCreate(
		CollectionInterface $collection,
		array $inputs,
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$bound = [];
		foreach ($inputs as $input) {
			$mutation = $this->parser->parse($collection, $input);
			$bound[] = $this->binder->bindCreate($session, $collection, $mutation);
		}

		return $this->executeWrite($session, $bound, $dispatchEvents, $output);
	}

	/**
	 * @param list<array{identity: PrimaryKeyValue|string|int, input: array<string, mixed>}> $items
	 * @param class-string $output
	 * @return list<array<string, mixed>>
	 */
	public function batchUpdate(
		CollectionInterface $collection,
		array $items,
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$bound = [];
		foreach ($items as $item) {
			$mutation = $this->parser->parse($collection, $item['input']);
			$bound[] = $this->binder->bindUpdate($session, $collection, $mutation, $item['identity']);
		}

		return $this->executeWrite($session, $bound, $dispatchEvents, $output);
	}

	/**
	 * @param list<PrimaryKeyValue|string|int> $identities
	 */
	public function batchDelete(
		CollectionInterface $collection,
		array $identities,
		bool $dispatchEvents = true,
	): bool {
		$session = $this->sessions->create();
		$this->binder->resetLookupCache();
		$bound = [];
		foreach ($identities as $identity) {
			$bound[] = $this->binder->bindDelete($session, $collection, $identity);
		}
		$this->executeDelete($session, $bound, $dispatchEvents);

		return true;
	}

	/**
	 * @param list<BoundMutation> $roots
	 * @param class-string $output
	 * @return list<array<string, mixed>>
	 */
	private function executeWrite(
		Session $session,
		array $roots,
		bool $dispatchEvents,
		string $output,
	): array {
		$plans = [];
		foreach ($roots as $bound) {
			$plans[] = MutationEventPlan::fromBound($bound);
		}
		$afterHooksTx = $this->hooks->start();

		try {
			if ($dispatchEvents) {
				foreach ($plans as $index => $plan) {
					$prevented = $this->dispatchBefore($plan, $roots[$index]);
					if ($prevented !== null) {
						$afterHooksTx->rollback();
						if (count($roots) !== 1) {
							throw RestApiError::mutationPrevented();
						}

						return [$prevented];
					}
					foreach ($plan->before as $item) {
						$this->reapplyHookMutations($session, $roots[$index], $item);
					}
				}
			}

			foreach ($roots as $bound) {
				$session->sync($bound->representation);
			}
			$session->flush();

			$results = [];
			foreach ($roots as $index => $bound) {
				$row = $this->reload($session, $bound);
				$bound->state->markReady($row);
				if ($dispatchEvents) {
					$this->scheduleAfter($afterHooksTx, $plans[$index], $bound);
				}
				$results[] = map($row)
					->using(CollectionRowMapper::class, $bound->collection)
					->from(PhpRepresentation::class)
					->as($output)
					->toArray();
			}

			if ($dispatchEvents) {
				$afterHooksTx->flush();
			}

			return $results;
		} catch (Throwable $throwable) {
			$afterHooksTx->rollback();

			throw $throwable;
		}
	}

	/**
	 * @param list<BoundMutation> $roots
	 */
	private function executeDelete(
		Session $session,
		array $roots,
		bool $dispatchEvents,
	): void {
		$plans = [];
		foreach ($roots as $bound) {
			$plans[] = MutationEventPlan::fromBound($bound);
		}
		$afterHooksTx = $this->hooks->start();

		try {
			if ($dispatchEvents) {
				foreach ($plans as $index => $plan) {
					$prevented = $this->dispatchBefore($plan, $roots[$index]);
					if ($prevented !== null) {
						$afterHooksTx->rollback();

						return;
					}
				}
			}

			foreach ($roots as $bound) {
				$session->sync($bound->representation);
			}
			$session->flush();

			if ($dispatchEvents) {
				foreach ($plans as $index => $plan) {
					$this->scheduleAfter($afterHooksTx, $plan, $roots[$index]);
				}
				$afterHooksTx->flush();
			}
		} catch (Throwable $throwable) {
			$afterHooksTx->rollback();

			throw $throwable;
		}
	}

	private function dispatchBefore(MutationEventPlan $plan, BoundMutation $root): ?array
	{
		$inheritNested = false;
		foreach ($plan->before as $bound) {
			$event = match ($bound->operation) {
				'create' => new ItemCreating(
					$bound->collection,
					$bound->state,
					$bound->path->toArray(),
					$root->state,
				),
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
				continue;
			}

			if ($inheritNested && ! $event->isRoot()) {
				$event->inheritNestedAuthorization();
				if ($event->getAuthState() === AuthState::Pending) {
					$event->allow();
				}
			}

			$this->hooks->dispatch($bound->collection, $bound->operation . '.before', $event);

			if ($event->isDefaultPrevented()) {
				if ($event->isRoot() && $event->getPreventResult() !== null) {
					return $event->getPreventResult();
				}

				throw RestApiError::mutationPrevented();
			}

			if ($event->isRoot() && method_exists($event, 'shouldInheritAuthToNested') && $event->shouldInheritAuthToNested()) {
				$inheritNested = true;
			}
		}

		return null;
	}

	private function scheduleAfter(
		RestHookTransaction $afterHooksTx,
		MutationEventPlan $plan,
		BoundMutation $root,
	): void {
		foreach ($plan->after as $bound) {
			$slot = $bound->operation . '.after';
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

	private function reapplyHookMutations(Session $session, BoundMutation $root, BoundMutation $bound): void
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

		$relatedSource = $bound->state->getRelatedSource();
		if ($relatedSource !== null && ! $bound->path->isRoot()) {
			$target = $relatedSource->getTargetObject();
			$projection = $session->projection($target);
			$source = $projection->from($bound->collection)->tracked();
			$refs = [];
			foreach ($pendingFields as $field) {
				$refs[] = $source->field($field);
			}
			$projection->properties(...$refs)->end();

			return;
		}

		$projection = $session->projection($root->representation);
		$source = $projection->from($root->collection)->tracked();
		$refs = [];
		foreach ($pendingFields as $field) {
			$refs[] = $source->field($field);
		}
		$projection->properties(...$refs)->end();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function reload(Session $session, BoundMutation $bound): array
	{
		$key = $this->resolveKeyAfterFlush($session, $bound);
		$row = $this->items->findByIdentity(
			$bound->collection,
			new PrimaryKeyValue($bound->collection, $key->getValues()),
		);
		if ($row === null) {
			throw RestApiError::notFound();
		}

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

		try {
			$state = $session->getRepresentations()->get($bound->representation);
			if ($state instanceof RepresentationState) {
				$record = $session->getRecords()->getFromRepresentation($state);
			}
		} catch (Throwable) {
			$record = null;
		}
		if ($record instanceof RecordState && $record->hasKey()) {
			return $record->getKey();
		}

		if ($bound->identity instanceof Key) {
			return $bound->identity;
		}

		$identity = $bound->state->getPrimaryKeyValue(false);
		if ($identity !== null && $identity->isComplete()) {
			/** @var non-empty-array<string, string|int|float|bool> $values */
			$values = $identity->values();

			return new Key($bound->collection, $values);
		}

		throw RestApiError::notFound();
	}

	private function requireIdentity(BoundMutation $bound): PrimaryKeyValue
	{
		if ($bound->identity instanceof Key) {
			return new PrimaryKeyValue($bound->collection, $bound->identity->getValues());
		}

		$identity = $bound->state->getPrimaryKeyValue(false);
		if ($identity === null) {
			throw RestApiError::notFound();
		}

		return $identity;
	}
}
