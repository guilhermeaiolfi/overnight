<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\Manual\Builder;
use ON\Data\ORM\Representation\Schema\Manual\PropertyRef;
use ON\Data\ORM\Representation\Schema\Manual\RelationRef;
use ON\Data\ORM\Representation\Schema\Manual\RelationRepresentationSource;
use ON\Data\ORM\Representation\Schema\Manual\RootRepresentationSource;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
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
use ON\RestApi\Support\PrimaryKeyValue;
use stdClass;

/**
 * Binds Directus-normalized mutations onto ON\Data Session projections and relation state.
 */
final class DirectusMutationBinder
{
	public function __construct(
		private readonly ItemRepositoryInterface $items,
	) {
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
		PrimaryKeyValue|Key|string|int $identity,
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
		PrimaryKeyValue|Key|string|int $identity,
	): BoundMutation {
		$key = $this->normalizeKey($collection, $identity);
		$previous = $this->items->findByIdentity(
			$collection,
			new PrimaryKeyValue($collection, $key->getValues()),
		);
		if ($previous === null) {
			throw RestApiError::notFound();
		}

		$representation = new stdClass();
		foreach ($previous as $field => $value) {
			$representation->{$field} = $value;
		}

		$projection = $session->projection($representation);
		$source = $projection->from($collection)->existing($key, $previous);
		$projection->properties($source->all())->end();
		$session->remove($representation);

		$state = new BoundItemState($collection, $representation, $previous, $previous, true);

		return new BoundMutation(
			operation: 'delete',
			collection: $collection,
			representation: $representation,
			state: $state,
			mutation: new DirectusMutation([], [], PayloadPath::root()),
			identity: $key,
		);
	}

	private function bindRoot(
		Session $session,
		CollectionInterface $collection,
		DirectusMutation $mutation,
		string $operation,
		?Key $identity,
	): BoundMutation {
		$representation = new stdClass();
		$values = $mutation->values;
		$seed = [];

		if ($operation === 'create') {
			$manualIdentity = PrimaryKey::of($collection)->extractFromInput($values);
			if ($manualIdentity !== null) {
				$seed = $manualIdentity->values();
				$identity = $this->normalizeKey($collection, $manualIdentity);
			}
		}

		if ($operation === 'update') {
			if ($identity === null) {
				throw RestApiError::notFound();
			}
			$previous = $this->items->findByIdentity(
				$collection,
				new PrimaryKeyValue($collection, $identity->getValues()),
			);
			if ($previous === null) {
				throw RestApiError::notFound();
			}
			$seed = $previous;
		}

		foreach ($values as $field => $value) {
			$name = (string) $field;
			if (! $collection->hasField($name)) {
				throw RestApiError::invalidField($name);
			}
			$representation->{$name} = $value;
		}

		$projection = $session->projection($representation);
		$source = $operation === 'create'
			? $projection->from($collection)->create($seed)
			: $projection->from($collection)->existing($identity, $seed);

		/** @var list<PropertyRef> $propertyRefs */
		$propertyRefs = [];
		foreach (array_keys($values) as $fieldName) {
			$name = (string) $fieldName;
			if ($collection->hasField($name)) {
				$propertyRefs[] = $source->field($name);
			}
		}

		$related = [];
		foreach ($mutation->relations as $relationMutation) {
			$boundRelations = $this->bindRelation(
				$session,
				$projection,
				$source,
				$representation,
				$relationMutation,
				$propertyRefs,
			);
			foreach ($boundRelations as $boundRelation) {
				$related[] = $boundRelation;
			}
		}

		if ($propertyRefs === []) {
			$projection->properties($source->all())->end();
		} else {
			$projection->properties(...$propertyRefs)->end();
		}

		$state = new BoundItemState(
			$collection,
			$representation,
			$values,
			$operation === 'update' ? $seed : null,
			$operation === 'update',
			$mutation->path,
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
			rootRecord: $source->getTargetRecord(),
		);
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindRelation(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $rootRepresentation,
		RelationMutation $relationMutation,
		array &$propertyRefs,
	): array {
		return match (true) {
			$relationMutation instanceof ToOneMutation => array_values(array_filter([
				$this->bindToOne(
					$session,
					$projection,
					$source,
					$rootRepresentation,
					$relationMutation,
					$propertyRefs,
				),
			])),
			$relationMutation instanceof ToManyImplicitMutation => $this->bindToManyImplicitMutation(
				$session,
				$projection,
				$source,
				$rootRepresentation,
				$relationMutation,
				$propertyRefs,
			),
			$relationMutation instanceof ToManyExplicitMutation => $this->bindToManyExplicitMutation(
				$session,
				$projection,
				$source,
				$rootRepresentation,
				$relationMutation,
				$propertyRefs,
			),
			default => [],
		};
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 */
	private function bindToOne(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $rootRepresentation,
		ToOneMutation $mutation,
		array &$propertyRefs,
	): ?BoundMutation {
		$mutation->assertValid();
		$relation = $mutation->relation();
		$relationName = $relation->getName();
		if (! $source instanceof RootRepresentationSource) {
			throw RestApiError::validationFailed([
				$mutation->path()->toString() => ['Nested to-one under a related item is not supported yet.'],
			]);
		}

		$relationRef = $source->{$relationName};
		if (! $relationRef instanceof RelationRef) {
			throw RestApiError::validationFailed([
				$mutation->path()->toString() => ['Unknown to-one relation.'],
			]);
		}

		if ($mutation->kind === ToOneKind::Clear) {
			$this->clearToOne($session, $source, $relationRef, $relationName);

			return null;
		}

		if ($mutation->kind === ToOneKind::Existing && $mutation->item === null && $mutation->identity !== null) {
			$projection->existing($relationRef, $mutation->identity);

			return null;
		}

		$item = $mutation->item;
		if ($item === null) {
			return null;
		}

		return $this->bindRelatedItem(
			$projection,
			$relationRef,
			$rootRepresentation,
			$item,
			$propertyRefs,
			$relationName,
		);
	}

	private function clearToOne(
		Session $session,
		RootRepresentationSource $source,
		RelationRef $relationRef,
		string $relationName,
	): void {
		$targetCollection = $relationRef->getDefinition()->getCollection();
		$ownerRecord = $source->getTargetRecord();
		$session->getRelations()->remove($ownerRecord, $relationName);

		$baseline = null;
		$currentKey = [];
		foreach ($relationRef->getDefinition()->getInnerKeys() as $index => $innerKey) {
			$outer = $relationRef->getDefinition()->getOuterKeys()[$index] ?? null;
			$value = $ownerRecord->getValue($innerKey);
			if ($value !== null && $outer !== null) {
				$currentKey[$outer] = $value;
			}
		}
		if ($currentKey !== []) {
			try {
				$baselineKey = $targetCollection->getKey($currentKey);
				$baseline = new stdClass();
				$session->identify($targetCollection, $baselineKey, $baseline);
			} catch (\Throwable) {
				$baseline = null;
			}
		}

		$toOne = new ToOneRelationState(
			$ownerRecord,
			$relationName,
			new RepresentationSchema($targetCollection),
			$baseline,
		);
		$session->getRelations()->add($toOne);
		$toOne->clear();
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindToManyImplicitMutation(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $rootRepresentation,
		ToManyImplicitMutation $mutation,
		array &$propertyRefs,
	): array {
		if (! $source instanceof RootRepresentationSource) {
			throw RestApiError::validationFailed([
				$mutation->path()->toString() => ['Nested to-many under a related item is not supported yet.'],
			]);
		}

		$relation = $mutation->relation();
		$relationName = $relation->getName();
		$relationRef = $source->{$relationName};
		if (! $relationRef instanceof RelationRef) {
			throw RestApiError::validationFailed([
				$mutation->path()->toString() => ['Unknown to-many relation.'],
			]);
		}

		$ownerRecord = $source->getTargetRecord();
		$targetCollection = $relation->getCollection();
		$relatedSchema = new RepresentationSchema($targetCollection);
		$baseline = $this->loadCurrentToManyTargets($session, $ownerRecord, $relation);
		$session->getRelations()->remove($ownerRecord, $relationName);
		$toMany = ToManyRelationState::full($ownerRecord, $relationName, $relatedSchema, $baseline);
		$session->getRelations()->add($toMany);

		$desired = [];
		$bound = [];
		foreach ($mutation->items as $item) {
			$reused = null;
			if (! $item->isNew() && $item->identity !== null) {
				foreach ($baseline as $existing) {
					$existingState = $session->getRepresentations()->get($existing);
					if (! $existingState instanceof \ON\Data\ORM\Representation\State\RepresentationState) {
						continue;
					}
					try {
						$existingRecord = $session->getRecords()->getFromRepresentation($existingState);
					} catch (\Throwable) {
						continue;
					}
					if (
						$existingRecord instanceof \ON\Data\ORM\Record\RecordState
						&& $existingRecord->hasKey()
						&& $existingRecord->getKey()?->equals($item->identity)
					) {
						$reused = $existing;

						break;
					}
				}
			}

			if ($reused !== null) {
				foreach ($item->values as $field => $value) {
					$alias = $this->aliasFor($relationName, (string) $field, $item->path);
					$rootRepresentation->{$alias} = $value;
					$childProjection = $session->projection($reused);
					$childSource = $childProjection->from($relation->getCollection())->tracked();
					if ($relation->getCollection()->hasField((string) $field)) {
						// Keep updates on the related object itself for sync.
						$reused->{(string) $field} = $value;
					}
				}
				$desired[spl_object_id($reused)] = $reused;
				$bound[] = new BoundMutation(
					operation: 'update',
					collection: $relation->getCollection(),
					representation: $reused,
					state: new BoundItemState(
						$relation->getCollection(),
						$reused,
						$item->values,
						null,
						true,
						$item->path,
					),
					mutation: new DirectusMutation($item->values, [], $item->path),
					identity: $item->identity,
					path: $item->path,
				);

				continue;
			}

			$childBound = $this->bindRelatedItem(
				$projection,
				$relationRef,
				$rootRepresentation,
				$item,
				$propertyRefs,
				$relationName,
			);
			$bound[] = $childBound;
			$targetObject = $this->relationTargetObject($session, $relationRef, $item);
			$desired[spl_object_id($targetObject)] = $targetObject;
		}

		foreach ($baseline as $existing) {
			if (isset($desired[spl_object_id($existing)])) {
				continue;
			}

			$matched = false;
			$existingState = $session->getRepresentations()->get($existing);
			if ($existingState instanceof \ON\Data\ORM\Representation\State\RepresentationState) {
				try {
					$existingRecord = $session->getRecords()->getFromRepresentation($existingState);
				} catch (\Throwable) {
					$existingRecord = null;
				}
				if ($existingRecord instanceof \ON\Data\ORM\Record\RecordState && $existingRecord->hasKey()) {
					foreach ($desired as $desiredObject) {
						$desiredState = $session->getRepresentations()->get($desiredObject);
						if (! $desiredState instanceof \ON\Data\ORM\Representation\State\RepresentationState) {
							continue;
						}
						try {
							$desiredRecord = $session->getRecords()->getFromRepresentation($desiredState);
						} catch (\Throwable) {
							continue;
						}
						if (
							$desiredRecord instanceof \ON\Data\ORM\Record\RecordState
							&& $desiredRecord->hasKey()
							&& $desiredRecord->getKey()?->equals($existingRecord->getKey())
						) {
							$matched = true;

							break;
						}
					}
				}
			}
			if (! $matched) {
				$toMany->remove($existing);
			}
		}

		return $bound;
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindToManyExplicitMutation(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $rootRepresentation,
		ToManyExplicitMutation $mutation,
		array &$propertyRefs,
	): array {
		if (! $source instanceof RootRepresentationSource) {
			throw RestApiError::validationFailed([
				$mutation->path()->toString() => ['Nested to-many under a related item is not supported yet.'],
			]);
		}

		$relation = $mutation->relation();
		$relationName = $relation->getName();
		$relationRef = $source->{$relationName};
		if (! $relationRef instanceof RelationRef) {
			throw RestApiError::validationFailed([
				$mutation->path()->toString() => ['Unknown to-many relation.'],
			]);
		}

		$bound = [];
		foreach ($mutation->create as $item) {
			$bound[] = $this->bindRelatedItem(
				$projection,
				$relationRef,
				$rootRepresentation,
				$item,
				$propertyRefs,
				$relationName,
			);
		}

		foreach ($mutation->update as $item) {
			$bound[] = $this->bindRelatedItem(
				$projection,
				$relationRef,
				$rootRepresentation,
				$item,
				$propertyRefs,
				$relationName,
			);
		}

		foreach ($mutation->delete as $key) {
			$previous = $this->items->findByIdentity(
				$relation->getCollection(),
				new PrimaryKeyValue($relation->getCollection(), $key->getValues()),
			);
			if ($previous === null) {
				throw RestApiError::notFound();
			}
			$representation = new stdClass();
			foreach ($previous as $field => $value) {
				$representation->{$field} = $value;
			}
			$childProjection = $session->projection($representation);
			$childSource = $childProjection->from($relation->getCollection())->existing($key, $previous);
			$childProjection->properties($childSource->all())->end();
			$session->remove($representation);
			$bound[] = new BoundMutation(
				operation: 'delete',
				collection: $relation->getCollection(),
				representation: $representation,
				state: new BoundItemState($relation->getCollection(), $representation, $previous, $previous, true, $mutation->path()),
				mutation: new DirectusMutation([], [], $mutation->path()),
				identity: $key,
				path: $mutation->path(),
			);
		}

		foreach ($mutation->unlink as $key) {
			$ownerRecord = $source->getTargetRecord();
			$relatedSchema = new RepresentationSchema($relation->getCollection());
			$state = $session->getRelations()->get($ownerRecord, $relationName);
			if (! $state instanceof ToManyRelationState) {
				$baseline = $this->loadCurrentToManyTargets($session, $ownerRecord, $relation);
				$state = ToManyRelationState::full($ownerRecord, $relationName, $relatedSchema, $baseline);
				$session->getRelations()->add($state);
			}

			$target = null;
			foreach ($state->getItems() as $item) {
				$itemState = $session->getRepresentations()->get($item);
				if (! $itemState instanceof \ON\Data\ORM\Representation\State\RepresentationState) {
					continue;
				}
				$record = $session->getRecords()->getFromRepresentation($itemState);
				if ($record instanceof \ON\Data\ORM\Record\RecordState && $record->hasKey() && $record->getKey()?->equals($key)) {
					$target = $item;

					break;
				}
			}

			if ($target === null) {
				$previous = $this->items->findByIdentity(
					$relation->getCollection(),
					new PrimaryKeyValue($relation->getCollection(), $key->getValues()),
				);
				$target = new stdClass();
				if ($previous !== null) {
					foreach ($previous as $field => $value) {
						$target->{$field} = $value;
					}
					$childProjection = $session->projection($target);
					$childSource = $childProjection->from($relation->getCollection())->existing($key, $previous);
					$childProjection->properties($childSource->all())->end();
				} else {
					$session->identify($relation->getCollection(), $key, $target);
				}
			}

			$state->remove($target);
		}

		return $bound;
	}

	/**
	 * @return list<object>
	 */
	private function loadCurrentToManyTargets(
		Session $session,
		\ON\Data\ORM\Record\RecordState $ownerRecord,
		\ON\Data\Definition\Relation\RelationInterface $relation,
	): array {
		if (! $ownerRecord->hasKey()) {
			return [];
		}

		$targetCollection = $relation->getCollection();
		$ownerKey = $ownerRecord->getKey();
		$query = $this->items->select($targetCollection, $targetCollection->getVisibleFields());

		if ($relation instanceof \ON\Data\Definition\Relation\M2MRelation) {
			// Load currently linked targets through the junction table via a simple query on through.
			$through = $relation->getThrough()->getCollection();
			$throughRows = $this->items->select($through)->fetchAll();
			$ownerValues = $ownerKey->getValues();
			$targetKeys = [];
			foreach ($throughRows as $row) {
				$matchesOwner = true;
				foreach ($relation->getThrough()->getInnerKeys() as $index => $throughInner) {
					$ownerField = $relation->getInnerKeys()[$index] ?? null;
					if ($ownerField === null || ($row[$throughInner] ?? null) != ($ownerValues[$ownerField] ?? null)) {
						$matchesOwner = false;

						break;
					}
				}
				if (! $matchesOwner) {
					continue;
				}
				$targetKeyValues = [];
				foreach ($relation->getThrough()->getOuterKeys() as $index => $throughOuter) {
					$targetField = $relation->getOuterKeys()[$index] ?? null;
					if ($targetField === null) {
						continue;
					}
					$targetKeyValues[$targetField] = $row[$throughOuter] ?? null;
				}
				if ($targetKeyValues !== []) {
					$targetKeys[] = $targetKeyValues;
				}
			}

			$objects = [];
			foreach ($targetKeys as $keyValues) {
				$row = $this->items->findByIdentity($targetCollection, new PrimaryKeyValue($targetCollection, $keyValues));
				if ($row === null) {
					continue;
				}
				$object = new stdClass();
				foreach ($row as $field => $value) {
					$object->{$field} = $value;
				}
				$key = $targetCollection->getKey($keyValues);
				$childProjection = $session->projection($object);
				$childSource = $childProjection->from($targetCollection)->existing($key, $row);
				$childProjection->properties($childSource->all())->end();
				$objects[] = $object;
			}

			return $objects;
		}

		// O2M / has-many: filter by outer keys = owner inner key values
		$criteria = [];
		foreach ($relation->getOuterKeys() as $index => $outerKey) {
			$innerKey = $relation->getInnerKeys()[$index] ?? null;
			if ($innerKey === null) {
				continue;
			}
			$criteria[$outerKey] = $ownerKey->getValues()[$innerKey] ?? null;
		}
		if ($criteria === []) {
			return [];
		}

		$rows = [];
		foreach ($this->items->select($targetCollection, $targetCollection->getVisibleFields())->fetchAll() as $row) {
			$match = true;
			foreach ($criteria as $field => $value) {
				if (($row[$field] ?? null) != $value) {
					$match = false;

					break;
				}
			}
			if ($match) {
				$rows[] = $row;
			}
		}

		$objects = [];
		foreach ($rows as $row) {
			$identity = PrimaryKey::of($targetCollection)->extractFromRow($row);
			if ($identity === null) {
				continue;
			}
			$object = new stdClass();
			foreach ($row as $field => $value) {
				$object->{$field} = $value;
			}
			$key = $this->normalizeKey($targetCollection, $identity);
			$childProjection = $session->projection($object);
			$source = $childProjection->from($targetCollection)->existing($key, $row);
			$childProjection->properties($source->all())->end();
			$objects[] = $object;
		}

		return $objects;
	}

	private function relationTargetObject(
		Session $session,
		RelationRef $relationRef,
		RelatedItemInput $item,
	): object {
		// Manual create/existing attaches an identity/target object; recover the latest added relation member.
		$owner = $relationRef->getOwner()->getTargetRecord();
		$state = $session->getRelations()->get($owner, $relationRef->getName());
		if ($state instanceof ToManyRelationState) {
			$items = $state->getItems();
			if ($items !== []) {
				return $items[array_key_last($items)];
			}
		}
		if ($state instanceof ToOneRelationState && $state->getTarget() !== null) {
			return $state->getTarget();
		}

		$fallback = new stdClass();
		if ($item->identity !== null) {
			$session->identify($relationRef->getDefinition()->getCollection(), $item->identity, $fallback);
		}

		return $fallback;
	}

	/**
	 * @param array<int, object> $desired
	 */
	private function isDesiredByKey(array $desired, object $existing, Session $session): bool
	{
		return false;
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 */
	private function bindRelatedItem(
		Builder $projection,
		RelationRef $relationRef,
		object $rootRepresentation,
		RelatedItemInput $item,
		array &$propertyRefs,
		string $relationName,
	): BoundMutation {
		$collection = $relationRef->getDefinition()->getCollection();
		if ($item->isNew()) {
			$seed = $item->values;
			if ($item->identity !== null) {
				$seed = $item->identity->getValues() + $seed;
			}
			$relatedSource = $projection->create($relationRef, $seed);
		} else {
			$seed = $item->identity?->getValues() ?? [];
			$previous = $this->items->findByIdentity(
				$collection,
				new PrimaryKeyValue($collection, $item->identity->getValues()),
			);
			if ($previous !== null) {
				$seed = $previous;
			}
			$relatedSource = $projection->existing($relationRef, $item->identity, $seed);
		}

		$fieldPaths = [];
		foreach ($item->values as $field => $value) {
			$alias = $this->aliasFor($relationName, (string) $field, $item->path);
			$fieldPaths[(string) $field] = $alias;
			$rootRepresentation->{$alias} = $value;
			if ($collection->hasField((string) $field)) {
				$propertyRefs[] = $relatedSource->field((string) $field)->as($alias);
			}
		}

		if ($item->relations !== []) {
			throw RestApiError::validationFailed([
				$item->path->toString() => ['Nested relation payloads under related items are not supported yet.'],
			]);
		}

		$state = new BoundItemState(
			$collection,
			$rootRepresentation,
			$item->values,
			null,
			! $item->isNew(),
			$item->path,
			$fieldPaths,
		);
		$state->setRelatedSource($relatedSource);

		return new BoundMutation(
			operation: $item->isNew() ? 'create' : 'update',
			collection: $collection,
			representation: $rootRepresentation,
			state: $state,
			mutation: new DirectusMutation($item->values, [], $item->path),
			identity: $item->identity,
			path: $item->path,
			related: [],
		);
	}

	private function aliasFor(string $relationName, string $field, PayloadPath $path): string
	{
		$suffix = $path->toString() !== '' ? str_replace('.', '_', $path->toString()) : $relationName;

		return '__rel_' . $suffix . '_' . $field;
	}

	private function normalizeKey(
		CollectionInterface $collection,
		PrimaryKeyValue|Key|string|int|array $identity,
	): Key {
		if ($identity instanceof Key) {
			return $identity;
		}

		if ($identity instanceof PrimaryKeyValue) {
			/** @var non-empty-array<string, string|int|float|bool> $values */
			$values = [];
			foreach ($identity->values() as $field => $value) {
				if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
					throw RestApiError::validationFailed([
						(string) $field => ['Primary key values must be scalar.'],
					]);
				}
				$values[(string) $field] = $value;
			}

			return new Key($collection, $values);
		}

		return $this->normalizeKey($collection, PrimaryKey::of($collection)->getValue($identity));
	}
}
