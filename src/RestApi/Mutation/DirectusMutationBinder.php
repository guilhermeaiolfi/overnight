<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\ValueRef;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Session;
use ON\Data\Schema\RepresentationSchema;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\Payload\DirectusMutation;
use ON\RestApi\Mutation\Payload\PayloadPath;
use ON\RestApi\Mutation\Payload\RelatedItemInput;
use ON\RestApi\Mutation\Payload\RelationMutation;
use ON\RestApi\Mutation\Payload\ToManyExplicitMutation;
use ON\RestApi\Mutation\Payload\ToManyImplicitMutation;
use ON\RestApi\Mutation\Payload\ToOneKind;
use ON\RestApi\Mutation\Payload\ToOneMutation;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKey;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Binds Directus-normalized mutations onto ON\Data Session save intents and relation state.
 *
 * Existing rows: writable SelectQuery load into the mutation Session (schema from the query).
 * Creates / field overlays: SelectQuery::projection() via MutationSchemaFactory.
 * Membership: ToManyRelationState::full + remove for implicit; Session::remove for deletes.
 *
 * Missing related identities:
 * - Scalar / identity-only references still require the row to exist (RELATED_NOT_FOUND).
 * - Nested objects with a body (field values and/or nested relations) whose identity is
 *   missing are promoted to create with the client-supplied primary key (upsert-style).
 * - Explicit `update` / `delete` remain strict via relation-scope checks.
 * - Exclusive has-many members omitted from an implicit array are deleted (not unlinked).
 *
 * Identity lookup cache: request-local (cleared per MutationCoordinator operation). It
 * memoizes DB existence reads only. Identities scheduled for Session::remove() in earlier
 * batch roots are treated as missing for later roots (stable RELATED_NOT_FOUND). Pending
 * Session creates are not visible to later roots — cross-root "create then reference" in
 * one batch is unsupported; existence is evaluated against committed rows plus pending removals.
 */
final class DirectusMutationBinder
{
	private readonly RelationBaselineReader $baselines;
	private readonly MutationSchemaFactory $schemas;

	/**
	 * Committed-row snapshot for this coordinator operation.
	 *
	 * @var array<string, array<string, mixed>|null> collection|hash => row
	 */
	private array $identityLookupCache = [];

	/**
	 * Identities scheduled for Session::remove() in this operation (batch-aware).
	 *
	 * @var array<string, true> collection|hash => true
	 */
	private array $pendingRemovedIdentities = [];

	public function __construct(
		private readonly ItemRepositoryInterface $items,
		DataRuntime|MutationSchemaFactory $runtimeOrSchemas,
		SessionFactory|RelationBaselineReader $sessionsOrBaselines,
	) {
		$this->schemas = $runtimeOrSchemas instanceof MutationSchemaFactory
			? $runtimeOrSchemas
			: new MutationSchemaFactory($runtimeOrSchemas, $items);
		$this->baselines = $sessionsOrBaselines instanceof RelationBaselineReader
			? $sessionsOrBaselines
			: new RelationBaselineReader($items, $sessionsOrBaselines);
	}

	public function resetLookupCache(): void
	{
		$this->identityLookupCache = [];
		$this->pendingRemovedIdentities = [];
	}

	/**
	 * Normalize the known root scalar fields without consulting the database.
	 * Upload objects are converted later by the authorized file hook.
	 */
	public function normalizeRootValues(CollectionInterface $collection, array $values, string $from): array
	{
		$fields = array_filter(
			$values,
			static fn ($value, $field): bool => $collection->hasField((string) $field) && ! $value instanceof UploadedFileInterface,
			ARRAY_FILTER_USE_BOTH,
		);
		if ($fields === []) {
			return $values;
		}

		$typed = $from === PhpRepresentation::class
			? $this->schemas->toPhpValues($collection, $fields)
			: map($fields)->args($collection)->from($from)->as(PhpRepresentation::class)->to([]);

		return array_replace($values, $typed);
	}

	public function bindCreate(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		?BoundItemState $state = null,
		bool $valuesArePhp = false,
	): BoundMutation {
		return $this->bindRoot($session, $collection, $mutation, 'create', null, $state, $valuesArePhp);
	}

	public function bindUpdate(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		Key|string|int $identity,
		?BoundItemState $state = null,
		bool $valuesArePhp = false,
	): BoundMutation {
		return $this->bindRoot(
			$session,
			$collection,
			$mutation,
			'update',
			$this->normalizeKey($collection, $identity),
			$state,
			$valuesArePhp,
		);
	}

	public function bindDelete(
		Session $session,
		CollectionInterface $collection,
		Key|string|int $identity,
		?BoundItemState $state = null,
	): BoundMutation {
		$key = $this->normalizeKey($collection, $identity);
		$previous = $this->findRow($collection, $key);
		if ($previous === null) {
			throw RestApiError::notFound();
		}

		return $this->stageRow(
			$session,
			$collection,
			new DirectusMutation([]),
			'delete',
			$key,
			$previous,
			state: $state,
		);
	}

	private function bindRoot(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		string $operation,
		?Key $identity,
		?BoundItemState $state,
		bool $valuesArePhp,
	): BoundMutation {
		$primaryKey = PrimaryKey::of($collection);
		$values = $operation === 'update' ? $primaryKey->withoutFields($mutation->values) : $mutation->values;
		foreach (array_keys($values) as $field) {
			if (! $collection->hasField((string) $field)) {
				throw RestApiError::invalidField((string) $field);
			}
		}
		if (! $valuesArePhp) {
			$values = $this->schemas->toPhpValues($collection, $values);
		}

		$previous = null;
		if ($operation === 'create') {
			$identity = $primaryKey->extractFromInput($values);
		} else {
			$previous = ($identity === null ? null : $this->findRow($collection, $identity))
				?? throw RestApiError::notFound();
		}

		$bound = $this->stageRow(
			$session,
			$collection,
			new DirectusMutation($values, $mutation->relations, $mutation->path),
			$operation,
			$identity,
			$previous,
			state: $state,
		);

		return $this->bindNested($session, $bound);
	}

	/**
	 * Stage the represented row and its hook state independently of membership.
	 *
	 * A retained member can supply its tracked key stub for a reference or a delete.
	 * Scalar patches use a writable projection to keep the clean baseline and the
	 * Session's conflict checks between different representations of the same row.
	 *
	 * @param array<string, mixed>|null $previous
	 * @param array<string, mixed> $submittedValues
	 * @param array<string, ValueRef> $parentKeys
	 */
	private function stageRow(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		string $operation,
		?Key $identity,
		?array $previous,
		?object $representation = null,
		?BoundItemState $state = null,
		array $submittedValues = [],
		array $parentKeys = [],
	): BoundMutation {
		$creating = $operation === 'create';
		$deleting = $operation === 'delete';
		$fields = array_map('strval', array_keys($mutation->values));
		$fieldPaths = array_combine($fields, $fields);

		if ($creating) {
			$seed = $mutation->values + ($identity?->getValues() ?? []);
			$representation = (object) $seed;
			$session->create($representation, $this->schemas->projectFields($collection, array_keys($seed)));
			$session->sync($representation);
		} else {
			assert($identity !== null);
			$representation ??= $this->schemas->loadWritable($session, $collection, $identity, $deleting ? null : $fields);
			if ($deleting) {
				$session->remove($representation);
				$this->pendingRemovedIdentities[$this->identityCacheKey($collection, $identity)] = true;
			}
		}

		$values = $deleting ? $previous : $mutation->values;
		assert($values !== null);
		if ($state === null) {
			$state = new BoundItemState(
				$collection,
				$representation,
				$values,
				$previous,
				! $creating,
				$mutation->path,
				$fieldPaths,
				identityMutable: $creating,
				creating: $creating,
			);
		} else {
			$state->attachRepresentation($representation, $values, $previous, $fieldPaths);
		}

		return new BoundMutation(
			operation: $operation,
			collection: $collection,
			representation: $representation,
			state: $state,
			mutation: $mutation,
			identity: $identity,
			path: $mutation->path,
			rootRecord: $this->requireRecord($session, $representation),
			submittedValues: $submittedValues,
			parentKeys: $parentKeys,
		);
	}

	private function bindNested(Session $session, BoundMutation $bound): BoundMutation
	{
		assert($bound->rootRecord !== null);
		$bound->related = $this->bindRelations($session, $bound->rootRecord, $bound->mutation->relations);

		return $bound;
	}

	/**
	 * @param list<RelationMutation> $relations
	 * @return list<BoundMutation>
	 */
	private function bindRelations(
		Session $session,
		RecordState $ownerRecord,
		array $relations,
	): array {
		$bound = [];
		foreach ($relations as $mutation) {
			if ($mutation->relation()->getCollection()->isHidden()) {
				throw RestApiError::invalidField($mutation->path()->toString());
			}

			$children = match (true) {
				$mutation instanceof ToOneMutation => $this->bindToOne($session, $ownerRecord, $mutation),
				$mutation instanceof ToManyImplicitMutation => $this->bindToManyImplicit($session, $ownerRecord, $mutation),
				$mutation instanceof ToManyExplicitMutation => $this->bindToManyExplicit($session, $ownerRecord, $mutation),
				default => [],
			};
			foreach ($children as $child) {
				$bound[] = $child;
			}
		}

		return $bound;
	}

	/** @return list<BoundMutation> */
	private function bindToOne(Session $session, RecordState $ownerRecord, ToOneMutation $mutation): array
	{
		$mutation->assertValid();
		$relation = $mutation->relation();
		if ($mutation->kind === ToOneKind::Clear) {
			$this->setToOne($session, $ownerRecord, $relation, null);

			return [];
		}

		if ($mutation->item !== null) {
			return [$this->bindAndLink($session, $ownerRecord, $relation, $mutation->item)];
		}

		if ($mutation->identity !== null) {
			$this->requireRelatedExists($relation, $mutation->identity, $mutation->path(), 'assign');
			$this->setToOne(
				$session,
				$ownerRecord,
				$relation,
				$session->identify($relation->getCollection(), $mutation->identity),
			);
		}

		return [];
	}

	private function setToOne(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
		?object $target,
	): void {
		$relationName = $relation->getName();
		$relations = $session->getRelations();
		$relations->remove($ownerRecord, $relationName);
		$toOne = new ToOneRelationState(
			$ownerRecord,
			$relationName,
			new RepresentationSchema($relation->getCollection()),
			$this->baselines->loadToOneIdentity($session, $ownerRecord, $relation),
		);
		$relations->add($toOne);
		$toOne->set($target);
	}

	/** @return list<BoundMutation> */
	private function bindToManyImplicit(
		Session $session,
		RecordState $ownerRecord,
		ToManyImplicitMutation $mutation,
	): array {
		$relation = $mutation->relation();
		$relationName = $relation->getName();
		$collection = $relation->getCollection();
		$baseline = $this->baselines->loadToMany($session, $ownerRecord, $relation);
		$relations = $session->getRelations();
		$relations->remove($ownerRecord, $relationName);
		$toMany = ToManyRelationState::full($ownerRecord, $relationName, new RepresentationSchema($collection), $baseline->items());
		$relations->add($toMany);

		$bound = [];
		$omitted = $baseline->itemsByNormalizedKey;
		foreach ($mutation->items as $item) {
			$identity = $item->identity;
			$retained = $item->forceNew || $identity === null ? null : $baseline->find($identity);
			if ($retained === null) {
				$bound[] = $this->bindAndLink($session, $ownerRecord, $relation, $item, $toMany);

				continue;
			}

			unset($omitted[$identity->getHash()]);
			$child = $this->stageRelatedItem($session, $relation, $item, retained: $retained);
			$bound[] = $this->bindNested($session, $child);
		}

		$deleteIndex = 0;
		foreach ($omitted as $hash => $existing) {
			$toMany->remove($existing);
			if (! $relation->isExclusive()) {
				continue;
			}

			$key = $baseline->keysByHash[$hash];
			$previous = $this->findRow($collection, $key);
			if ($previous !== null) {
				$path = $mutation->path()->append('delete')->append($deleteIndex++);
				$bound[] = $this->stageRow(
					$session,
					$collection,
					new DirectusMutation([], path: $path),
					'delete',
					$key,
					$previous,
					representation: $existing,
				);
			}
		}

		return $bound;
	}

	/** @return list<BoundMutation> */
	private function bindToManyExplicit(
		Session $session,
		RecordState $ownerRecord,
		ToManyExplicitMutation $mutation,
	): array {
		$relation = $mutation->relation();
		$collection = $relation->getCollection();
		$baseline = $this->baselines->loadToMany($session, $ownerRecord, $relation);
		$bound = [];

		$toMany = $session->getRelations()->getOrCreate(
			$ownerRecord,
			$relation->getName(),
			RelationCardinality::MANY,
			new RepresentationSchema($collection),
		);
		assert($toMany instanceof ToManyRelationState);

		foreach ($mutation->create as $item) {
			$bound[] = $this->bindAndLink($session, $ownerRecord, $relation, $item, $toMany);
		}

		foreach ($mutation->update as $item) {
			$this->assertInRelationScope($baseline, $item->identity, $relation, $item->path, 'update');
			$child = $this->stageRelatedItem($session, $relation, $item, allowUpsert: false);
			$bound[] = $this->bindNested($session, $child);
		}

		foreach ($mutation->delete as $index => $key) {
			$path = $mutation->path()->append('delete')->append((int) $index);
			$this->assertInRelationScope($baseline, $key, $relation, $path, 'delete');
			$previous = $this->requireRelatedExists($relation, $key, $path, 'delete');
			$bound[] = $this->stageRow($session, $collection, new DirectusMutation([], path: $path), 'delete', $key, $previous);
		}

		return $bound;
	}

	private function bindAndLink(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
		RelatedItemInput $item,
		?ToManyRelationState $toMany = null,
	): BoundMutation {
		$parentKeys = [];
		if ($relation instanceof HasManyRelation || $relation instanceof HasOneRelation) {
			foreach ($relation->getKeyPairing()->getPairs() as $pair) {
				$parentKeys[$pair['right']] = $ownerRecord->getValueRef($pair['left']);
			}
		}

		$child = $this->stageRelatedItem($session, $relation, $item, parentKeys: $parentKeys);
		if ($toMany === null) {
			$this->setToOne($session, $ownerRecord, $relation, $child->representation);
		} else {
			$toMany->add($child->representation);
		}

		return $this->bindNested($session, $child);
	}

	/** @param array<string, ValueRef> $parentKeys */
	private function stageRelatedItem(
		Session $session,
		RelationInterface $relation,
		RelatedItemInput $item,
		?object $retained = null,
		bool $allowUpsert = true,
		array $parentKeys = [],
	): BoundMutation {
		$collection = $relation->getCollection();
		$values = $this->schemas->toPhpValues($collection, $item->values);
		$identity = $item->identity;
		$creating = $item->isNew();
		$previous = null;
		if (! $creating) {
			assert($identity !== null);
			$previous = $this->findRow($collection, $identity);
			if ($previous === null) {
				$creating = $allowUpsert
					&& $retained === null
					&& ($item->values !== [] || $item->relations !== [])
					&& ! isset($this->pendingRemovedIdentities[$this->identityCacheKey($collection, $identity)]);
				if (! $creating) {
					throw $this->relatedNotFound($relation, $identity, $item->path, $allowUpsert ? 'reference' : 'update');
				}
			}
		}

		return $this->stageRow(
			$session,
			$collection,
			new DirectusMutation($values, $item->relations, $item->path),
			$creating ? 'create' : 'update',
			$identity,
			$previous,
			representation: $values === [] ? $retained : null,
			submittedValues: $item->values,
			parentKeys: $parentKeys,
		);
	}

	private function assertInRelationScope(
		RelationBaseline $baseline,
		?Key $identity,
		RelationInterface $relation,
		PayloadPath $path,
		string $operation,
	): void {
		if ($identity === null) {
			throw RestApiError::validationFailed([
				$path->toString() => ['Related item identity is required.'],
			]);
		}

		if ($baseline->contains($identity)) {
			return;
		}

		$this->requireRelatedExists($relation, $identity, $path, $operation);

		throw RestApiError::relationTargetOutOfScope(
			$relation->getCollection()->getName(),
			$identity->isComposite() ? $identity->getValues() : $identity->getValue(),
			$relation->getName(),
			$path->toString(),
			$operation,
		);
	}

	/** @return array<string, mixed> */
	private function requireRelatedExists(
		RelationInterface $relation,
		Key $identity,
		PayloadPath $path,
		string $operation,
	): array {
		return $this->findRow($relation->getCollection(), $identity)
			?? throw $this->relatedNotFound($relation, $identity, $path, $operation);
	}

	private function relatedNotFound(
		RelationInterface $relation,
		Key $identity,
		PayloadPath $path,
		string $operation,
	): RestApiError {
		return RestApiError::relatedNotFound(
			$relation->getCollection()->getName(),
			$identity->isComposite() ? $identity->getValues() : $identity->getValue(),
			$relation->getName(),
			$path->toString(),
			$operation,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findRow(CollectionInterface $collection, Key $identity): ?array
	{
		$cacheKey = $this->identityCacheKey($collection, $identity);
		if (isset($this->pendingRemovedIdentities[$cacheKey])) {
			return null;
		}

		if (array_key_exists($cacheKey, $this->identityLookupCache)) {
			return $this->identityLookupCache[$cacheKey];
		}

		$row = $this->items->findByIdentity(
			$collection,
			$identity,
		);
		$this->identityLookupCache[$cacheKey] = $row;

		return $row;
	}

	private function identityCacheKey(CollectionInterface $collection, Key $identity): string
	{
		return $collection->getName() . '|' . $identity->getHash();
	}

	private function requireRecord(Session $session, object $representation): RecordState
	{
		$record = $session->getRepresentations()->get($representation)?->getSingleRecord();
		if ($record === null) {
			throw RestApiError::validationFailed([
				'_root' => ['Unable to resolve Session record for mutation binding.'],
			]);
		}

		return $record;
	}

	private function normalizeKey(
		CollectionInterface $collection,
		Key|string|int|array $identity,
	): Key {
		if ($identity instanceof Key) {
			return $identity;
		}

		return PrimaryKey::of($collection)->getValue($identity);
	}
}
