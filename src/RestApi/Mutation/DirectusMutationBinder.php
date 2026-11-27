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
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\State\RepresentationState;
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
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKey;
use Psr\Http\Message\UploadedFileInterface;
use stdClass;

/**
 * Binds Directus-normalized mutations onto ON\Data Session save intents and relation state.
 *
 * Existing rows use a writable SelectQuery or a complete snapshot from the built-in repository.
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
	private readonly bool $reusePatchSnapshots;

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
		$this->reusePatchSnapshots = $items::class === ItemRepository::class
			&& $runtimeOrSchemas instanceof DataRuntime;
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

	/** Set $singleRoot only when this is the sole root in a new mutation Session. */
	public function bindCreate(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		?BoundItemState $state = null,
		bool $valuesArePhp = false,
		bool $singleRoot = false,
	): BoundMutation {
		return $this->bindRoot($session, $collection, $mutation, 'create', null, $state, $valuesArePhp, $singleRoot);
	}

	/** Set $singleRoot only when this is the sole root in a new mutation Session. */
	public function bindUpdate(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		Key|string|int $identity,
		?BoundItemState $state = null,
		bool $valuesArePhp = false,
		bool $singleRoot = false,
	): BoundMutation {
		return $this->bindRoot(
			$session,
			$collection,
			$mutation,
			'update',
			$this->normalizeKey($collection, $identity),
			$state,
			$valuesArePhp,
			$singleRoot,
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

		$representation = $this->schemas->loadWritable($session, $collection, $key);
		$session->remove($representation);
		$this->markPendingRemoved($collection, $key);

		if ($state === null) {
			$state = new BoundItemState($collection, $representation, $previous, $previous, true, identityMutable: false, creating: false);
		} else {
			$state->attachRepresentation($representation, $previous, $previous);
		}

		return new BoundMutation(
			operation: 'delete',
			collection: $collection,
			representation: $representation,
			state: $state,
			mutation: new DirectusMutation([], [], PayloadPath::root()),
			identity: $key,
			rootRecord: $this->requireRecord($session, $representation),
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
		bool $singleRoot,
	): BoundMutation {
		$values = $mutation->values;
		if ($operation === 'update') {
			$values = PrimaryKey::of($collection)->withoutFields($values);
		}
		$previous = null;
		$scalarWrite = $singleRoot && $mutation->relations === [];

		foreach (array_keys($values) as $fieldName) {
			if (! $collection->hasField((string) $fieldName)) {
				throw RestApiError::invalidField((string) $fieldName);
			}
		}

		if (! $valuesArePhp) {
			$values = $this->schemas->toPhpValues($collection, $values);
		}
		$fieldNames = array_keys($values);
		$fieldPaths = array_combine($fieldNames, $fieldNames);

		if ($operation === 'create') {
			$manualIdentity = PrimaryKey::of($collection)->extractFromInput($values);
			if ($manualIdentity !== null) {
				$identity = $this->normalizeKey($collection, $manualIdentity);
			}

			$representation = (object) ($values + ($manualIdentity?->getValues() ?? []));
			$schema = $this->schemas->projectFields($collection, array_keys(get_object_vars($representation)));
			$session->create($representation, $schema);
			if (! $scalarWrite) {
				// Bind owners in order so later roots and related writes see the same Session state.
				$session->sync($representation);
			}
		} else {
			if ($identity === null) {
				throw RestApiError::notFound();
			}

			$previous = $this->findRow($collection, $identity);
			if ($previous === null) {
				throw RestApiError::notFound();
			}

			$keyFields = $identity->getValues();
			if ($scalarWrite && $this->reusePatchSnapshots && array_diff_key($values + $keyFields, $previous) === []) {
				$key = $collection->getKey(array_intersect_key($previous, $keyFields));
				$baseline = $key->getValues() + array_intersect_key($previous, $values);
				$representation = (object) $baseline;
				$schema = $this->schemas->projectFields($collection, array_keys($baseline));

				// A clean baseline keeps unchanged attributes out of the pending PATCH.
				$session->identify($collection, $key, $representation, $schema);
				$session->update($representation, $schema)->identity($key);
			} else {
				$representation = $this->schemas->loadWritable($session, $collection, $identity, $fieldNames);
			}
		}

		if ($state === null) {
			$state = new BoundItemState(
				$collection,
				$representation,
				$values,
				$previous,
				$operation === 'update',
				$mutation->path,
				$fieldPaths,
				identityMutable: $operation === 'create',
				creating: $operation === 'create',
			);
		} else {
			$state->attachRepresentation($representation, $values, $previous, $fieldPaths);
		}

		$ownerRecord = $scalarWrite ? null : $this->requireRecord($session, $representation);
		$related = $ownerRecord === null
			? []
			: $this->bindRelations($session, $representation, $ownerRecord, $mutation->relations);

		return new BoundMutation(
			operation: $operation,
			collection: $collection,
			representation: $representation,
			state: $state,
			mutation: $mutation,
			identity: $identity,
			path: $mutation->path,
			related: $related,
			rootRecord: $ownerRecord,
		);
	}

	/**
	 * @param list<RelationMutation> $relations
	 * @return list<BoundMutation>
	 */
	private function bindRelations(
		Session $session,
		object $ownerRepresentation,
		RecordState $ownerRecord,
		array $relations,
	): array {
		$bound = [];
		foreach ($relations as $relationMutation) {
			foreach ($this->bindRelationMutation(
				$session,
				$ownerRepresentation,
				$ownerRecord,
				$relationMutation,
			) as $child) {
				$bound[] = $child;
			}
		}

		return $bound;
	}

	/**
	 * @return list<BoundMutation>
	 */
	private function bindRelationMutation(
		Session $session,
		object $ownerRepresentation,
		RecordState $ownerRecord,
		RelationMutation $relationMutation,
	): array {
		if ($relationMutation->relation()->getCollection()->isHidden()) {
			throw RestApiError::invalidField($relationMutation->path()->toString());
		}

		return match (true) {
			$relationMutation instanceof ToOneMutation => array_values(array_filter([
				$this->bindToOne(
					$session,
					$ownerRepresentation,
					$ownerRecord,
					$relationMutation,
				),
			])),
			$relationMutation instanceof ToManyImplicitMutation => $this->bindToManyImplicit(
				$session,
				$ownerRepresentation,
				$ownerRecord,
				$relationMutation,
			),
			$relationMutation instanceof ToManyExplicitMutation => $this->bindToManyExplicit(
				$session,
				$ownerRepresentation,
				$ownerRecord,
				$relationMutation,
			),
			default => [],
		};
	}

	private function bindToOne(
		Session $session,
		object $ownerRepresentation,
		RecordState $ownerRecord,
		ToOneMutation $mutation,
	): ?BoundMutation {
		$mutation->assertValid();
		$relation = $mutation->relation();

		if ($mutation->kind === ToOneKind::Clear) {
			$this->clearToOne($session, $ownerRecord, $relation);

			return null;
		}

		if ($mutation->kind === ToOneKind::Existing && $mutation->item === null && $mutation->identity !== null) {
			$this->requireRelatedExists(
				$relation->getCollection(),
				$mutation->identity,
				$mutation->path(),
				$relation->getName(),
				'assign',
			);
			$this->assignToOneIdentity($session, $ownerRecord, $relation, $mutation->identity);

			return null;
		}

		$item = $mutation->item;
		if ($item === null) {
			return null;
		}

		return $this->bindRelatedItem(
			$session,
			$ownerRepresentation,
			$ownerRecord,
			$relation,
			$item,
			cardinality: RelationCardinality::SINGLE,
		);
	}

	private function clearToOne(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
	): void {
		$relationName = $relation->getName();
		$targetCollection = $relation->getCollection();
		$session->getRelations()->remove($ownerRecord, $relationName);

		$baseline = $this->baselines->loadToOneIdentity($session, $ownerRecord, $relation);
		$toOne = new ToOneRelationState(
			$ownerRecord,
			$relationName,
			new RepresentationSchema($targetCollection),
			$baseline,
		);
		$session->getRelations()->add($toOne);
		$toOne->clear();
	}

	private function assignToOneIdentity(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
		Key $identity,
	): void {
		$relationName = $relation->getName();
		$targetCollection = $relation->getCollection();
		$session->getRelations()->remove($ownerRecord, $relationName);

		$baseline = $this->baselines->loadToOneIdentity($session, $ownerRecord, $relation);
		$target = $session->identify($targetCollection, $identity);
		$toOne = new ToOneRelationState(
			$ownerRecord,
			$relationName,
			new RepresentationSchema($targetCollection),
			$baseline,
		);
		$session->getRelations()->add($toOne);
		$toOne->set($target);
	}

	/**
	 * @return list<BoundMutation>
	 */
	private function bindToManyImplicit(
		Session $session,
		object $ownerRepresentation,
		RecordState $ownerRecord,
		ToManyImplicitMutation $mutation,
	): array {
		$relation = $mutation->relation();
		$relationName = $relation->getName();
		$targetCollection = $relation->getCollection();
		$relatedSchema = new RepresentationSchema($targetCollection);

		$baseline = $this->baselines->loadToMany($session, $ownerRecord, $relation);
		$session->getRelations()->remove($ownerRecord, $relationName);
		$toMany = ToManyRelationState::full($ownerRecord, $relationName, $relatedSchema, $baseline->items());
		$session->getRelations()->add($toMany);

		$desired = [];
		$bound = [];
		$exclusiveDeleteIndex = 0;

		foreach ($mutation->items as $item) {
			$reused = null;
			if (! $item->isNew() && $item->identity !== null) {
				$reused = $baseline->find($item->identity);
			}

			if ($reused !== null) {
				$childBound = $this->bindExistingRelatedObject(
					$session,
					$reused,
					$targetCollection,
					$item,
				);
				$desired[spl_object_id($reused)] = $reused;
				$bound[] = $childBound;

				continue;
			}

			$childBound = $this->bindRelatedItem(
				$session,
				$ownerRepresentation,
				$ownerRecord,
				$relation,
				$item,
				cardinality: RelationCardinality::MANY,
				toMany: $toMany,
			);
			$bound[] = $childBound;
			$desired[spl_object_id($childBound->representation)] = $childBound->representation;
		}

		$desiredKeys = [];
		foreach ($desired as $object) {
			$key = $this->recordFor($session, $object)?->getKey();
			if ($key !== null) {
				$desiredKeys[$key->getHash()] = true;
			}
		}

		foreach ($baseline->items() as $existing) {
			$key = $this->recordFor($session, $existing)?->getKey();
			if (isset($desired[spl_object_id($existing)]) || ($key !== null && isset($desiredKeys[$key->getHash()]))) {
				continue;
			}

			$toMany->remove($existing);

			if ($relation->isExclusive()) {
				$deleteBound = $this->bindExclusiveImplicitRemoval(
					$session,
					$existing,
					$targetCollection,
					$mutation->path()->append('delete')->append($exclusiveDeleteIndex),
				);
				if ($deleteBound !== null) {
					$bound[] = $deleteBound;
					++$exclusiveDeleteIndex;
				}
			}
		}

		return $bound;
	}

	/**
	 * Delete an omitted exclusive has-many member using the baseline representation
	 * already tracked in the Session, so RestApi delete hooks still fire.
	 *
	 * Baseline identities from {@see RelationBaselineReader} are PK stubs; reload the
	 * full row so delete.after hooks (e.g. file cleanup) still see fields like file_id.
	 */
	private function bindExclusiveImplicitRemoval(
		Session $session,
		object $existing,
		CollectionInterface $collection,
		PayloadPath $path,
	): ?BoundMutation {
		$record = $this->recordFor($session, $existing);
		if (! $record instanceof RecordState || ! $record->hasKey()) {
			return null;
		}

		$key = $record->getKey();
		if ($key === null) {
			return null;
		}

		$previous = $this->findRow($collection, $key);
		if ($previous === null) {
			return null;
		}

		foreach ($previous as $field => $value) {
			$existing->{$field} = $value;
		}

		$session->remove($existing);
		$this->markPendingRemoved($collection, $key);

		return new BoundMutation(
			operation: 'delete',
			collection: $collection,
			representation: $existing,
			state: new BoundItemState(
				$collection,
				$existing,
				$previous,
				$previous,
				true,
				$path,
				identityMutable: false,
				creating: false,
			),
			mutation: new DirectusMutation([], [], $path),
			identity: $key,
			path: $path,
		);
	}

	/**
	 * @return list<BoundMutation>
	 */
	private function bindToManyExplicit(
		Session $session,
		object $ownerRepresentation,
		RecordState $ownerRecord,
		ToManyExplicitMutation $mutation,
	): array {
		$relation = $mutation->relation();
		$baseline = $this->baselines->loadToMany($session, $ownerRecord, $relation);
		$bound = [];

		$toMany = $session->getRelations()->getOrCreate(
			$ownerRecord,
			$relation->getName(),
			RelationCardinality::MANY,
			new RepresentationSchema($relation->getCollection()),
		);
		assert($toMany instanceof ToManyRelationState);

		foreach ($mutation->create as $item) {
			$bound[] = $this->bindRelatedItem(
				$session,
				$ownerRepresentation,
				$ownerRecord,
				$relation,
				$item,
				cardinality: RelationCardinality::MANY,
				toMany: $toMany,
			);
		}

		foreach ($mutation->update as $item) {
			$this->assertInRelationScope(
				$baseline,
				$item->identity,
				$relation,
				$item->path,
				'update',
			);
			$bound[] = $this->bindRelatedItem(
				$session,
				$ownerRepresentation,
				$ownerRecord,
				$relation,
				$item,
				cardinality: RelationCardinality::MANY,
				toMany: $toMany,
				requireExists: true,
				existenceOperation: 'update',
				linkRelation: false,
			);
		}

		foreach ($mutation->delete as $index => $key) {
			$deletePath = $mutation->path()->append('delete')->append((int) $index);
			$this->assertInRelationScope(
				$baseline,
				$key,
				$relation,
				$deletePath,
				'delete',
			);
			$bound[] = $this->bindExplicitDelete(
				$session,
				$relation->getCollection(),
				$key,
				$deletePath,
				$relation->getName(),
			);
		}

		return $bound;
	}

	private function bindExplicitDelete(
		Session $session,
		CollectionInterface $collection,
		Key $key,
		PayloadPath $path,
		string $relationName,
	): BoundMutation {
		$previous = $this->requireRelatedExists(
			$collection,
			$key,
			$path,
			$relationName,
			'delete',
		);

		$representation = $this->schemas->loadWritable($session, $collection, $key);
		$session->remove($representation);
		$this->markPendingRemoved($collection, $key);

		return new BoundMutation(
			operation: 'delete',
			collection: $collection,
			representation: $representation,
			state: new BoundItemState(
				$collection,
				$representation,
				$previous,
				$previous,
				true,
				$path,
				identityMutable: false,
				creating: false,
			),
			mutation: new DirectusMutation([], [], $path),
			identity: $key,
			path: $path,
		);
	}

	private function bindRelatedItem(
		Session $session,
		object $ownerRepresentation,
		RecordState $ownerRecord,
		RelationInterface $relation,
		RelatedItemInput $item,
		RelationCardinality $cardinality,
		?ToManyRelationState $toMany = null,
		bool $requireExists = true,
		string $existenceOperation = 'reference',
		bool $linkRelation = true,
	): BoundMutation {
		$collection = $relation->getCollection();
		$relationName = $relation->getName();
		/** @var array<string, mixed>|null $previous */
		$previous = null;
		$itemValues = $this->schemas->toPhpValues($collection, $item->values);

		if (! $item->isNew() && $item->identity !== null) {
			$previous = $this->findRow($collection, $item->identity);
			if ($previous === null && $this->hasRelatedBody($item)) {
				$item = new RelatedItemInput(
					$item->identity,
					$item->values,
					$item->relations,
					$item->path,
					forceNew: true,
				);
			} elseif ($previous === null && $requireExists) {
				throw RestApiError::relatedNotFound(
					$collection->getName(),
					$item->identity->isComposite() ? $item->identity->getValues() : $item->identity->getValue(),
					$relationName,
					$item->path->toString(),
					$existenceOperation,
				);
			}
		}

		/** @var array<string, string> $fieldPaths */
		$fieldPaths = [];

		if ($item->isNew()) {
			$childObject = new stdClass();
			$seedFields = [];
			if ($item->identity !== null) {
				foreach ($item->identity->getValues() as $field => $value) {
					$childObject->{$field} = $value;
					$seedFields[] = (string) $field;
				}
			}
			foreach ($itemValues as $field => $value) {
				$name = (string) $field;
				if (! $collection->hasField($name)) {
					continue;
				}
				$childObject->{$name} = $value;
				$fieldPaths[$name] = $name;
				$seedFields[] = $name;
			}

			$map = $this->schemas->projectFields($collection, $seedFields);
			$session->create($childObject, $map);
			$session->sync($childObject);
		} else {
			$loadFields = [];
			foreach (array_keys($itemValues) as $fieldName) {
				$name = (string) $fieldName;
				if ($collection->hasField($name)) {
					$loadFields[] = $name;
					$fieldPaths[$name] = $name;
				}
			}
			assert($item->identity !== null);
			$childObject = $this->schemas->loadWritable($session, $collection, $item->identity, $loadFields);
			foreach ($itemValues as $field => $value) {
				$name = (string) $field;
				if ($collection->hasField($name)) {
					$childObject->{$name} = $value;
				}
			}
		}

		$parentKeys = [];
		if ($linkRelation) {
			$this->attachRelated(
				$session,
				$ownerRecord,
				$relation,
				$cardinality,
				$childObject,
				$toMany,
			);
			if ($relation instanceof HasManyRelation || $relation instanceof HasOneRelation) {
				foreach ($relation->getKeyPairing()->getPairs() as $pair) {
					$parentKeys[$pair['right']] = $ownerRecord->getValueRef($pair['left']);
				}
			}
		}

		$related = $this->bindNestedOnRelated(
			$session,
			$childObject,
			$item,
		);

		$state = new BoundItemState(
			$collection,
			$childObject,
			$itemValues,
			$previous,
			! $item->isNew(),
			$item->path,
			$fieldPaths,
			identityMutable: $item->isNew(),
			creating: $item->isNew(),
		);

		return new BoundMutation(
			operation: $item->isNew() ? 'create' : 'update',
			collection: $collection,
			representation: $childObject,
			state: $state,
			mutation: new DirectusMutation($itemValues, $item->relations, $item->path),
			identity: $item->identity,
			path: $item->path,
			related: $related,
			rootRecord: $this->recordFor($session, $childObject),
			submittedValues: $item->values,
			parentKeys: $parentKeys,
		);
	}

	private function hasRelatedBody(RelatedItemInput $item): bool
	{
		return $item->values !== [] || $item->relations !== [];
	}

	private function bindExistingRelatedObject(
		Session $session,
		object $representation,
		CollectionInterface $collection,
		RelatedItemInput $item,
	): BoundMutation {
		$previous = $item->identity !== null
			? $this->findRow($collection, $item->identity)
			: null;

		if ($item->values === [] && $item->relations === []) {
			return new BoundMutation(
				operation: 'update',
				collection: $collection,
				representation: $representation,
				state: new BoundItemState(
					$collection,
					$representation,
					[],
					$previous,
					true,
					$item->path,
					identityMutable: false,
					creating: false,
				),
				mutation: new DirectusMutation([], [], $item->path),
				identity: $item->identity,
				path: $item->path,
			);
		}

		/** @var array<string, string> $fieldPaths */
		$fieldPaths = [];
		$itemValues = $this->schemas->toPhpValues($collection, $item->values);
		$loadFields = [];
		foreach ($itemValues as $field => $_value) {
			$name = (string) $field;
			if (! $collection->hasField($name)) {
				continue;
			}
			$loadFields[] = $name;
			$fieldPaths[$name] = $name;
		}

		if ($loadFields !== []) {
			assert($item->identity !== null);
			// Baseline members are Session::identify() PK stubs. update()+sync() no-ops
			// on PK-only schemas, and RecordState::setValue always dirties missing fields —
			// which emits no-op UPDATEs that MySQL reports as affected_rows=0.
			// Hydrate writable fields (same as explicit/root update), absorb that snapshot
			// as clean, then assign overlays so only real changes dirty the record.
			$representation = $this->schemas->loadWritable(
				$session,
				$collection,
				$item->identity,
				$loadFields,
			);
			$record = $this->requireRecord($session, $representation);
			if ($record->isDirty()) {
				$record->markClean();
			}

			foreach ($itemValues as $field => $value) {
				$name = (string) $field;
				if ($collection->hasField($name)) {
					$representation->{$name} = $value;
				}
			}
		}

		$related = $this->bindNestedOnRelated(
			$session,
			$representation,
			$item,
		);

		return new BoundMutation(
			operation: 'update',
			collection: $collection,
			representation: $representation,
			state: new BoundItemState(
				$collection,
				$representation,
				$itemValues,
				$previous,
				true,
				$item->path,
				$fieldPaths,
				identityMutable: false,
				creating: false,
			),
			mutation: new DirectusMutation($itemValues, $item->relations, $item->path),
			identity: $item->identity,
			path: $item->path,
			related: $related,
			submittedValues: $item->values,
		);
	}

	/**
	 * @return list<BoundMutation>
	 */
	private function bindNestedOnRelated(
		Session $session,
		object $childObject,
		RelatedItemInput $item,
	): array {
		if ($item->relations === []) {
			return [];
		}

		$childRecord = $this->requireRecord($session, $childObject);

		return $this->bindRelations(
			$session,
			$childObject,
			$childRecord,
			$item->relations,
		);
	}

	private function attachRelated(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
		RelationCardinality $cardinality,
		object $childObject,
		?ToManyRelationState $toMany,
	): void {
		$relationName = $relation->getName();
		$relatedSchema = new RepresentationSchema($relation->getCollection());

		if ($cardinality->isMany()) {
			$state = $toMany ?? $session->getRelations()->getOrCreate(
				$ownerRecord,
				$relationName,
				RelationCardinality::MANY,
				$relatedSchema,
			);
			assert($state instanceof ToManyRelationState);
			$state->add($childObject);

			return;
		}

		$session->getRelations()->remove($ownerRecord, $relationName);
		$baseline = $this->baselines->loadToOneIdentity($session, $ownerRecord, $relation);
		$toOne = new ToOneRelationState(
			$ownerRecord,
			$relationName,
			$relatedSchema,
			$baseline,
		);
		$session->getRelations()->add($toOne);
		$toOne->set($childObject);
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

		$row = $this->findRow($relation->getCollection(), $identity);
		if ($row === null) {
			throw RestApiError::relatedNotFound(
				$relation->getCollection()->getName(),
				$identity->isComposite() ? $identity->getValues() : $identity->getValue(),
				$relation->getName(),
				$path->toString(),
				$operation,
			);
		}

		throw RestApiError::relationTargetOutOfScope(
			$relation->getCollection()->getName(),
			$identity->isComposite() ? $identity->getValues() : $identity->getValue(),
			$relation->getName(),
			$path->toString(),
			$operation,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function requireRelatedExists(
		CollectionInterface $collection,
		Key $identity,
		PayloadPath $path,
		string $relationName,
		string $operation,
	): array {
		$row = $this->findRow($collection, $identity);
		if ($row !== null) {
			return $row;
		}

		throw RestApiError::relatedNotFound(
			$collection->getName(),
			$identity->isComposite() ? $identity->getValues() : $identity->getValue(),
			$relationName,
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

	private function markPendingRemoved(CollectionInterface $collection, Key $identity): void
	{
		$cacheKey = $this->identityCacheKey($collection, $identity);
		$this->pendingRemovedIdentities[$cacheKey] = true;
		$this->identityLookupCache[$cacheKey] = null;
	}

	private function identityCacheKey(CollectionInterface $collection, Key $identity): string
	{
		return $collection->getName() . '|' . $identity->getHash();
	}

	private function requireRecord(Session $session, object $representation): RecordState
	{
		$record = $this->recordFor($session, $representation);
		if (! $record instanceof RecordState) {
			throw RestApiError::validationFailed([
				'_root' => ['Unable to resolve Session record for mutation binding.'],
			]);
		}

		return $record;
	}

	private function recordFor(Session $session, object $representation): ?RecordState
	{
		$state = $session->getRepresentations()->get($representation);
		if (! $state instanceof RepresentationState) {
			return null;
		}

		$record = $state->getSingleRecord();
		if ($record instanceof RecordState) {
			return $record;
		}

		return null;
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
