<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
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
use stdClass;

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

	public function bindCreate(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
	): BoundMutation {
		return $this->bindRoot($session, $collection, $mutation, 'create', null);
	}

	public function bindUpdate(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		Key|string|int $identity,
	): BoundMutation {
		return $this->bindRoot(
			$session,
			$collection,
			$mutation,
			'update',
			$this->normalizeKey($collection, $identity),
		);
	}

	public function bindDelete(
		Session $session,
		CollectionInterface $collection,
		Key|string|int $identity,
	): BoundMutation {
		$key = $this->normalizeKey($collection, $identity);
		$previous = $this->findRow($collection, $key);
		if ($previous === null) {
			throw RestApiError::notFound();
		}

		$representation = $this->schemas->loadWritable($session, $collection, $key);
		$session->remove($representation);
		$this->markPendingRemoved($collection, $key);

		$state = new BoundItemState($collection, $representation, $previous, $previous, true, identityMutable: false, creating: false);

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
	): BoundMutation {
		$values = $mutation->values;
		/** @var array<string, mixed>|null $previous */
		$previous = null;
		/** @var array<string, string> $fieldPaths */
		$fieldPaths = [];

		foreach (array_keys($values) as $fieldName) {
			$name = (string) $fieldName;
			if (! $collection->hasField($name)) {
				throw RestApiError::invalidField($name);
			}
		}

		if ($operation === 'create') {
			$representation = new stdClass();
			$values = $this->schemas->toPhpValues($collection, $values);
			$manualIdentity = PrimaryKey::of($collection)->extractFromInput($values);
			if ($manualIdentity !== null) {
				$identity = $this->normalizeKey($collection, $manualIdentity);
				foreach ($manualIdentity->getValues() as $field => $value) {
					$representation->{$field} = $value;
				}
			}

			foreach ($values as $field => $value) {
				$name = (string) $field;
				$representation->{$name} = $value;
				$fieldPaths[$name] = $name;
			}

			$fieldNames = array_keys($fieldPaths);
			if ($identity !== null) {
				foreach ($identity->getValues() as $field => $_) {
					$fieldNames[] = (string) $field;
				}
			}
			$map = $this->schemas->projectFields($collection, $fieldNames);
			$session->create($representation, $map);
			$session->sync($representation);
		} else {
			if ($identity === null) {
				throw RestApiError::notFound();
			}
			$previous = $this->findRow($collection, $identity);
			if ($previous === null) {
				throw RestApiError::notFound();
			}

			$values = $this->schemas->toPhpValues($collection, $values);
			$loadFields = [];
			foreach (array_keys($values) as $fieldName) {
				$name = (string) $fieldName;
				$loadFields[] = $name;
				$fieldPaths[$name] = $name;
			}

			$representation = $this->schemas->loadWritable($session, $collection, $identity, $loadFields);
			foreach ($values as $field => $value) {
				$representation->{(string) $field} = $value;
			}
		}

		$ownerRecord = $this->requireRecord($session, $representation);
		$related = $this->bindRelations(
			$session,
			$representation,
			$ownerRecord,
			$mutation->relations,
		);

		$state = new BoundItemState(
			$collection,
			$representation,
			$values,
			$operation === 'update' ? $previous : null,
			$operation === 'update',
			$mutation->path,
			$fieldPaths,
			identityMutable: $operation === 'create',
			creating: $operation === 'create',
		);

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

		foreach ($baseline->items() as $existing) {
			if (isset($desired[spl_object_id($existing)])) {
				continue;
			}

			if ($this->matchesAnyDesiredKey($session, $desired, $existing)) {
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

		if ($linkRelation) {
			$this->attachRelated(
				$session,
				$ownerRecord,
				$relation,
				$cardinality,
				$childObject,
				$toMany,
			);
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
		$overlayFields = [];
		foreach ($itemValues as $field => $value) {
			$name = (string) $field;
			if (! $collection->hasField($name)) {
				continue;
			}
			$representation->{$name} = $value;
			$fieldPaths[$name] = $name;
			$overlayFields[] = $name;
		}

		if ($overlayFields !== []) {
			$map = $this->schemas->projectFields($collection, $overlayFields);
			$session->update($representation, $map);
			$session->sync($representation);
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

	/**
	 * @param array<int, object> $desired
	 */
	private function matchesAnyDesiredKey(Session $session, array $desired, object $existing): bool
	{
		$existingRecord = $this->recordFor($session, $existing);
		if (! $existingRecord instanceof RecordState || ! $existingRecord->hasKey()) {
			return false;
		}

		foreach ($desired as $desiredObject) {
			$desiredRecord = $this->recordFor($session, $desiredObject);
			if (
				$desiredRecord instanceof RecordState
				&& $desiredRecord->hasKey()
				&& $desiredRecord->getKey()?->equals($existingRecord->getKey())
			) {
				return true;
			}
		}

		return false;
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
