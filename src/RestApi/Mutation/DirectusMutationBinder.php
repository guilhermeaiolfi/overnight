<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\Manual\Builder;
use ON\Data\ORM\Representation\Schema\Manual\PropertyRef;
use ON\Data\ORM\Representation\Schema\Manual\RelationRef;
use ON\Data\ORM\Representation\Schema\Manual\RelationRepresentationSource;
use ON\Data\ORM\Representation\Schema\Manual\RootRepresentationSource;
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
use ON\RestApi\Support\PrimaryKeyValue;
use stdClass;
use Throwable;

/**
 * Binds Directus-normalized mutations onto ON\Data Session projections and relation state.
 *
 * Expresses membership intent only. ON\Data planners own FK / through-row persistence.
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
		SessionFactory|RelationBaselineReader $sessionsOrBaselines,
	) {
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
		$previous = $this->findRow($collection, $key);
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
		$this->markPendingRemoved($collection, $key);

		$state = new BoundItemState($collection, $representation, $previous, $previous, true, identityMutable: false);

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
			$previous = $this->findRow($collection, $identity);
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

		$related = $this->bindRelations(
			$session,
			$projection,
			$source,
			$representation,
			$mutation->relations,
			$propertyRefs,
		);

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
			identityMutable: $operation === 'create',
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
	 * @param list<RelationMutation> $relations
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindRelations(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $ownerRepresentation,
		array $relations,
		array &$propertyRefs,
	): array {
		$bound = [];
		foreach ($relations as $relationMutation) {
			foreach ($this->bindRelationMutation(
				$session,
				$projection,
				$source,
				$ownerRepresentation,
				$relationMutation,
				$propertyRefs,
			) as $child) {
				$bound[] = $child;
			}
		}

		return $bound;
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindRelationMutation(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $ownerRepresentation,
		RelationMutation $relationMutation,
		array &$propertyRefs,
	): array {
		return match (true) {
			$relationMutation instanceof ToOneMutation => array_values(array_filter([
				$this->bindToOne(
					$session,
					$projection,
					$source,
					$ownerRepresentation,
					$relationMutation,
					$propertyRefs,
				),
			])),
			$relationMutation instanceof ToManyImplicitMutation => $this->bindToManyImplicit(
				$session,
				$projection,
				$source,
				$ownerRepresentation,
				$relationMutation,
				$propertyRefs,
			),
			$relationMutation instanceof ToManyExplicitMutation => $this->bindToManyExplicit(
				$session,
				$projection,
				$source,
				$ownerRepresentation,
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
		object $ownerRepresentation,
		ToOneMutation $mutation,
		array &$propertyRefs,
	): ?BoundMutation {
		$mutation->assertValid();
		$relation = $mutation->relation();
		$relationRef = $this->relationRef($source, $relation, $mutation->path());

		if ($mutation->kind === ToOneKind::Clear) {
			$this->clearToOne($session, $source->getTargetRecord(), $relation);

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
			$projection->existing($relationRef, $mutation->identity);

			return null;
		}

		$item = $mutation->item;
		if ($item === null) {
			return null;
		}

		return $this->bindRelatedItem(
			$session,
			$projection,
			$relationRef,
			$ownerRepresentation,
			$item,
			$propertyRefs,
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

	/**
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindToManyImplicit(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $ownerRepresentation,
		ToManyImplicitMutation $mutation,
		array &$propertyRefs,
	): array {
		$relation = $mutation->relation();
		$relationName = $relation->getName();
		$relationRef = $this->relationRef($source, $relation, $mutation->path());
		$ownerRecord = $source->getTargetRecord();
		$targetCollection = $relation->getCollection();
		$relatedSchema = new RepresentationSchema($targetCollection);

		$baseline = $this->baselines->loadToMany($session, $ownerRecord, $relation);
		$session->getRelations()->remove($ownerRecord, $relationName);
		$toMany = ToManyRelationState::full($ownerRecord, $relationName, $relatedSchema, $baseline->items());
		$session->getRelations()->add($toMany);

		$desired = [];
		$bound = [];

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

			if (! $item->isNew() && $item->identity !== null) {
				$this->requireRelatedExists(
					$targetCollection,
					$item->identity,
					$item->path,
					$relationName,
					$item->values === [] && $item->relations === [] ? 'assign' : 'update',
				);
			}

			$childBound = $this->bindRelatedItem(
				$session,
				$projection,
				$relationRef,
				$ownerRepresentation,
				$item,
				$propertyRefs,
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
		}

		return $bound;
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 * @return list<BoundMutation>
	 */
	private function bindToManyExplicit(
		Session $session,
		Builder $projection,
		RootRepresentationSource|RelationRepresentationSource $source,
		object $ownerRepresentation,
		ToManyExplicitMutation $mutation,
		array &$propertyRefs,
	): array {
		$relation = $mutation->relation();
		$relationRef = $this->relationRef($source, $relation, $mutation->path());
		$ownerRecord = $source->getTargetRecord();
		$baseline = $this->baselines->loadToMany($session, $ownerRecord, $relation);
		$bound = [];

		foreach ($mutation->create as $item) {
			$bound[] = $this->bindRelatedItem(
				$session,
				$projection,
				$relationRef,
				$ownerRepresentation,
				$item,
				$propertyRefs,
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
				$projection,
				$relationRef,
				$ownerRepresentation,
				$item,
				$propertyRefs,
				requireExists: true,
				existenceOperation: 'update',
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

		$representation = new stdClass();
		foreach ($previous as $field => $value) {
			$representation->{$field} = $value;
		}

		$childProjection = $session->projection($representation);
		$childSource = $childProjection->from($collection)->existing($key, $previous);
		$childProjection->properties($childSource->all())->end();
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
			),
			mutation: new DirectusMutation([], [], $path),
			identity: $key,
			path: $path,
		);
	}

	/**
	 * @param list<PropertyRef> $propertyRefs
	 */
	private function bindRelatedItem(
		Session $session,
		Builder $projection,
		RelationRef $relationRef,
		object $ownerRepresentation,
		RelatedItemInput $item,
		array &$propertyRefs,
		bool $requireExists = true,
		string $existenceOperation = 'reference',
	): BoundMutation {
		$collection = $relationRef->getDefinition()->getCollection();
		$relationName = $relationRef->getName();
		/** @var array<string, mixed>|null $previous */
		$previous = null;

		if ($item->isNew()) {
			$seed = $item->values;
			if ($item->identity !== null) {
				$seed = $item->identity->getValues() + $seed;
			}
			$relatedSource = $projection->create($relationRef, $seed);
		} else {
			if ($item->identity !== null && $requireExists) {
				$previous = $this->requireRelatedExists(
					$collection,
					$item->identity,
					$item->path,
					$relationName,
					$existenceOperation,
				);
			} elseif ($item->identity !== null) {
				$previous = $this->findRow($collection, $item->identity);
			}
			$seed = $previous ?? ($item->identity?->getValues() ?? []);
			$relatedSource = $projection->existing($relationRef, $item->identity, $seed);
		}

		$childObject = $relatedSource->getTargetObject();
		$fieldPaths = [];
		foreach ($item->values as $field => $value) {
			$name = (string) $field;
			if (! $collection->hasField($name)) {
				continue;
			}
			$alias = $this->aliasFor($relationRef->getName(), $name, $item->path);
			$fieldPaths[$name] = $alias;
			$ownerRepresentation->{$alias} = $value;
			$propertyRefs[] = $relatedSource->field($name)->as($alias);
		}

		$related = $this->bindNestedOnRelated(
			$session,
			$relatedSource,
			$childObject,
			$collection,
			$item,
		);

		$state = new BoundItemState(
			$collection,
			$ownerRepresentation,
			$item->values,
			$previous,
			! $item->isNew(),
			$item->path,
			$fieldPaths,
			identityMutable: $item->isNew(),
		);
		$state->setRelatedSource($relatedSource);

		return new BoundMutation(
			operation: $item->isNew() ? 'create' : 'update',
			collection: $collection,
			representation: $childObject,
			state: $state,
			mutation: new DirectusMutation($item->values, $item->relations, $item->path),
			identity: $item->identity,
			path: $item->path,
			related: $related,
		);
	}

	private function bindExistingRelatedObject(
		Session $session,
		object $representation,
		CollectionInterface $collection,
		RelatedItemInput $item,
	): BoundMutation {
		if ($item->values === [] && $item->relations === []) {
			return new BoundMutation(
				operation: 'update',
				collection: $collection,
				representation: $representation,
				state: new BoundItemState(
					$collection,
					$representation,
					[],
					null,
					true,
					$item->path,
					identityMutable: false,
				),
				mutation: new DirectusMutation([], [], $item->path),
				identity: $item->identity,
				path: $item->path,
			);
		}

		foreach ($item->values as $field => $value) {
			$name = (string) $field;
			if ($collection->hasField($name)) {
				$representation->{$name} = $value;
			}
		}

		$childProjection = $session->projection($representation);
		$childSource = $childProjection->from($collection)->tracked();
		$propertyRefs = [];
		foreach (array_keys($item->values) as $fieldName) {
			$name = (string) $fieldName;
			if ($collection->hasField($name)) {
				$propertyRefs[] = $childSource->field($name);
			}
		}
		if ($propertyRefs !== []) {
			$childProjection->properties(...$propertyRefs)->end();
		}

		$nestedPropertyRefs = [];
		$related = $this->bindRelations(
			$session,
			$childProjection,
			$childSource,
			$representation,
			$item->relations,
			$nestedPropertyRefs,
		);
		if ($nestedPropertyRefs !== []) {
			$childProjection->properties(...$nestedPropertyRefs)->end();
		}

		return new BoundMutation(
			operation: 'update',
			collection: $collection,
			representation: $representation,
			state: new BoundItemState(
				$collection,
				$representation,
				$item->values,
				null,
				true,
				$item->path,
				identityMutable: false,
			),
			mutation: new DirectusMutation($item->values, $item->relations, $item->path),
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
		RelationRepresentationSource $relatedSource,
		object $childObject,
		CollectionInterface $collection,
		RelatedItemInput $item,
	): array {
		if ($item->relations === []) {
			return [];
		}

		// Nested relations are bound on the related identity object so recursion
		// uses RootRepresentationSource relation navigation without storage details.
		$childProjection = $session->projection($childObject);
		$childRoot = $childProjection->from($collection)->tracked();
		$nestedPropertyRefs = [];
		$bound = $this->bindRelations(
			$session,
			$childProjection,
			$childRoot,
			$childObject,
			$item->relations,
			$nestedPropertyRefs,
		);

		if ($nestedPropertyRefs !== []) {
			$childProjection->properties(...$nestedPropertyRefs)->end();
		}

		return $bound;
	}

	private function relationRef(
		RootRepresentationSource|RelationRepresentationSource $source,
		RelationInterface $relation,
		PayloadPath $path,
	): RelationRef {
		if ($source instanceof RootRepresentationSource) {
			$ref = $source->{$relation->getName()};
			if (! $ref instanceof RelationRef) {
				throw RestApiError::validationFailed([
					$path->toString() => ['Unknown relation.'],
				]);
			}

			return $ref;
		}

		return new RelationRef($source, $relation->getName(), $relation);
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

		// Distinguish missing globally vs present but out of scope.
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
			new PrimaryKeyValue($collection, $identity->getValues()),
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

	private function recordFor(Session $session, object $representation): ?RecordState
	{
		$state = $session->getRepresentations()->get($representation);
		if (! $state instanceof RepresentationState) {
			return null;
		}

		try {
			$record = $session->getRecords()->getFromRepresentation($state);
		} catch (Throwable) {
			return null;
		}

		return $record instanceof RecordState ? $record : null;
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
