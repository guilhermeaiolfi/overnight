<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\DataRuntime;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Mutation\Event\MutationEventPlan;
use ON\RestApi\Mutation\Payload\DirectusMutation;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyValue;
use Throwable;

/**
 * Directus payload → binder → hooks → Session sync/flush → reload.
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
	 */
	public function create(
		CollectionInterface $collection,
		array $input,
		array $files = [],
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		$mutation = $this->parser->parse($collection, $input, $files);
		$session = $this->sessions->create();
		$bound = $this->binder->bindCreate($session, $collection, $mutation);

		return $this->executeWrite($session, $bound, $dispatchEvents, $output);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param class-string $output
	 */
	public function update(
		CollectionInterface $collection,
		PrimaryKeyValue|string|int $identity,
		array $input,
		array $files = [],
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		$mutation = $this->parser->parse($collection, $input, $files);
		$session = $this->sessions->create();
		$bound = $this->binder->bindUpdate($session, $collection, $mutation, $identity);

		return $this->executeWrite($session, $bound, $dispatchEvents, $output);
	}

	public function delete(
		CollectionInterface $collection,
		PrimaryKeyValue|string|int $identity,
		bool $dispatchEvents = true,
	): bool {
		$session = $this->sessions->create();
		$bound = $this->binder->bindDelete($session, $collection, $identity);
		$plan = MutationEventPlan::fromBound($bound);
		$afterHooksTx = $this->hooks->start();

		try {
			if ($dispatchEvents) {
				$this->dispatchBefore($plan, $bound);
			}

			$session->sync($bound->representation);
			$session->flush();

			if ($dispatchEvents) {
				$this->scheduleAfter($afterHooksTx, $plan, $bound);
				$afterHooksTx->flush();
			}

			return true;
		} catch (Throwable $throwable) {
			$afterHooksTx->rollback();

			throw $throwable;
		}
	}

	/**
	 * Execute an already-parsed mutation on a shared session (batch).
	 *
	 * @param class-string $output
	 */
	public function executeBound(
		Session $session,
		BoundMutation $bound,
		bool $dispatchEvents = true,
		string $output = PhpRepresentation::class,
	): array {
		return $this->executeWrite($session, $bound, $dispatchEvents, $output, false);
	}

	/**
	 * @param class-string $output
	 * @return array<string, mixed>
	 */
	private function executeWrite(
		Session $session,
		BoundMutation $bound,
		bool $dispatchEvents,
		string $output,
		bool $flush = true,
	): array {
		$plan = MutationEventPlan::fromBound($bound);
		$afterHooksTx = $this->hooks->start();

		try {
			if ($dispatchEvents) {
				$this->dispatchBefore($plan, $bound);
				foreach ($plan->before as $item) {
					$this->reapplyHookMutations($session, $bound, $item);
				}
			}

			$session->sync($bound->representation);
			if ($flush) {
				$session->flush();
			}

			$row = $this->reload($session, $bound);
			$bound->state->markReady($row);

			if ($dispatchEvents) {
				$this->scheduleAfter($afterHooksTx, $plan, $bound);
				$afterHooksTx->flush();
			}

			return map($row)
				->using(CollectionRowMapper::class, $bound->collection)
				->from(PhpRepresentation::class)
				->as($output)
				->toArray();
		} catch (Throwable $throwable) {
			$afterHooksTx->rollback();

			throw $throwable;
		}
	}

	private function dispatchBefore(MutationEventPlan $plan, BoundMutation $root): void
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
				if ($event->getAuthState() === \ON\RestApi\Event\AuthState::Pending) {
					$event->allow();
				}
			}

			$this->hooks->dispatch($bound->collection, $bound->operation . '.before', $event);

			if ($event->isRoot() && method_exists($event, 'shouldInheritAuthToNested') && $event->shouldInheritAuthToNested()) {
				$inheritNested = true;
			}
		}
	}

	private function scheduleAfter(
		\ON\RestApi\Hook\RestHookTransaction $afterHooksTx,
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

		// Refresh representation writes (including newly mapped hook fields).
		$bound->state->setData($data);

		$pendingFields = $bound->state->pullPendingHookFields();
		if ($pendingFields === []) {
			return;
		}

		$relatedSource = $bound->state->getRelatedSource();
		if ($relatedSource !== null && ! $bound->path->isRoot()) {
			// Project onto the related identity object. Flattened root aliases share
			// sourcePathKey for MANY items, so overlaying on the root can attach a
			// hook field to the wrong related record.
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
