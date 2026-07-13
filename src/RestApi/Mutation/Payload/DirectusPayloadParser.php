<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKey;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Directus wire-format parser → compact normalized mutation model.
 * Does not query, plan, or persist.
 */
final class DirectusPayloadParser
{
	private const DETAILED_KEYS = ['create', 'update', 'delete'];

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 */
	public function parse(
		CollectionInterface $collection,
		array $input,
		array $files = [],
		?PayloadPath $path = null,
	): DirectusMutation {
		$path ??= PayloadPath::root();
		if ($files !== []) {
			$input = $this->mergeFiles($collection, $input, $files);
		}

		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);

		return new DirectusMutation(
			values: $scalars,
			relations: $this->parseRelations($collection, $relations, $path),
			path: $path,
		);
	}

	/**
	 * @param array<string, mixed> $relations
	 * @return list<RelationMutation>
	 */
	private function parseRelations(CollectionInterface $collection, array $relations, PayloadPath $path): array
	{
		$parsed = [];

		foreach ($relations as $relationName => $rawInput) {
			$name = (string) $relationName;
			if (! $collection->relations->has($name)) {
				continue;
			}

			$relation = $collection->relations->get($name);
			$relationPath = $path->append($name);
			if ($relation instanceof FirstOfManyRelation) {
				throw RestApiError::validationFailed([
					$relationPath->toString() => ['First-of-many relations are read-only for mutations.'],
				]);
			}

			$parsed[] = $relation->getCardinality()->isSingle()
				? $this->parseToOne($relation, $rawInput, $relationPath)
				: $this->parseToMany($relation, $rawInput, $relationPath);
		}

		return $parsed;
	}

	private function parseToOne(RelationInterface $relation, mixed $rawInput, PayloadPath $path): ToOneMutation
	{
		if ($rawInput === null) {
			return ToOneMutation::clear($relation, $path);
		}

		$target = $relation->getCollection();

		if (! is_array($rawInput)) {
			$identity = $this->identityFromScalar($target, $rawInput, $path);

			return ToOneMutation::existing($relation, $path, $identity);
		}

		if (! MutationInput::isAssociativeArray($rawInput)) {
			throw RestApiError::validationFailed([
				$path->toString() !== '' ? $path->toString() : '_root' => ['To-one relation payload must be null, an identity, or an object.'],
			]);
		}

		$item = $this->parseRelatedItem($target, $rawInput, $path);

		return ToOneMutation::item($relation, $path, $item);
	}

	private function parseToMany(RelationInterface $relation, mixed $rawInput, PayloadPath $path): RelationMutation
	{
		if ($rawInput === null) {
			throw RestApiError::validationFailed([
				$path->toString() => ['To-many relation payload cannot be null.'],
			]);
		}

		if (! is_array($rawInput)) {
			throw RestApiError::validationFailed([
				$path->toString() => ['To-many relation payload must be an array or detailed object.'],
			]);
		}

		if (MutationInput::isAssociativeArray($rawInput) && $this->isDetailedPayload($rawInput)) {
			return $this->parseDetailedToMany($relation, $rawInput, $path);
		}

		$items = MutationInput::isAssociativeArray($rawInput) ? [$rawInput] : $rawInput;
		$parsedItems = [];
		$seenIdentities = [];
		foreach ($items as $index => $item) {
			$itemPath = $path->append((int) $index);
			$parsed = $this->parseRelatedItemOrIdentity(
				$relation->getCollection(),
				$item,
				$itemPath,
			);
			if ($parsed->identity !== null) {
				$this->assertUniqueIdentity(
					$relation,
					$parsed->identity,
					$itemPath,
					$seenIdentities,
				);
			}
			$parsedItems[] = $parsed;
		}

		return new ToManyImplicitMutation($relation, $path, $parsedItems);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseDetailedToMany(RelationInterface $relation, array $input, PayloadPath $path): ToManyExplicitMutation
	{
		$target = $relation->getCollection();
		$create = [];
		$update = [];
		$delete = [];
		/** @var array<string, string> $seenUpdateOrDelete hash => operation */
		$seenUpdateOrDelete = [];
		/** @var array<string, true> $seenCreate */
		$seenCreate = [];

		foreach (array_keys($input) as $key) {
			if (! in_array((string) $key, self::DETAILED_KEYS, true)) {
				throw RestApiError::validationFailed([
					$path->toString() => [sprintf("Unknown detailed relation operation '%s'.", (string) $key)],
				]);
			}
		}

		foreach (MutationInput::normalizeRelationItems($input['create'] ?? []) as $index => $item) {
			if (! is_array($item)) {
				throw RestApiError::validationFailed([
					$path->append('create')->append((int) $index)->toString() => ['Create payloads must be objects.'],
				]);
			}
			$itemPath = $path->append('create')->append((int) $index);
			$related = $this->parseRelatedItem($target, $item, $itemPath, forceNew: true);
			if ($related->identity !== null) {
				$hash = $related->identity->getHash();
				if (isset($seenCreate[$hash]) || isset($seenUpdateOrDelete[$hash])) {
					throw RestApiError::duplicateRelatedIdentity(
						$target->getName(),
						$related->identity->getValues(),
						$relation->getName(),
						$itemPath->toString(),
					);
				}
				$seenCreate[$hash] = true;
			}
			$create[] = $related;
		}

		foreach (MutationInput::normalizeRelationItems($input['update'] ?? []) as $index => $item) {
			if (! is_array($item)) {
				throw RestApiError::validationFailed([
					$path->append('update')->append((int) $index)->toString() => ['Update payloads must be objects with an identity.'],
				]);
			}
			$itemPath = $path->append('update')->append((int) $index);
			$related = $this->parseRelatedItem($target, $item, $itemPath);
			if ($related->identity === null) {
				throw RestApiError::validationFailed([
					$itemPath->toString() => ['Update payloads require an identity.'],
				]);
			}
			$this->assertUniqueExplicitIdentity(
				$relation,
				$related->identity,
				$itemPath,
				$seenUpdateOrDelete,
				$seenCreate,
				'update',
			);
			$update[] = $related;
		}

		foreach (MutationInput::normalizeRelationItems($input['delete'] ?? []) as $index => $item) {
			$itemPath = $path->append('delete')->append((int) $index);
			$key = $this->identityFromMixed($target, $item, $itemPath);
			$this->assertUniqueExplicitIdentity(
				$relation,
				$key,
				$itemPath,
				$seenUpdateOrDelete,
				$seenCreate,
				'delete',
			);
			$delete[] = $key;
		}

		return new ToManyExplicitMutation($relation, $path, $create, $update, $delete);
	}

	/**
	 * @param array<string, true> $seenIdentities
	 */
	private function assertUniqueIdentity(
		RelationInterface $relation,
		Key $identity,
		PayloadPath $path,
		array &$seenIdentities,
	): void {
		$hash = $identity->getHash();
		if (isset($seenIdentities[$hash])) {
			throw RestApiError::duplicateRelatedIdentity(
				$relation->getCollection()->getName(),
				$identity->getValues(),
				$relation->getName(),
				$path->toString(),
			);
		}
		$seenIdentities[$hash] = true;
	}

	/**
	 * @param array<string, string> $seenUpdateOrDelete
	 * @param array<string, true> $seenCreate
	 */
	private function assertUniqueExplicitIdentity(
		RelationInterface $relation,
		Key $identity,
		PayloadPath $path,
		array &$seenUpdateOrDelete,
		array $seenCreate,
		string $operation,
	): void {
		$hash = $identity->getHash();
		if (isset($seenCreate[$hash]) || isset($seenUpdateOrDelete[$hash])) {
			throw RestApiError::duplicateRelatedIdentity(
				$relation->getCollection()->getName(),
				$identity->getValues(),
				$relation->getName(),
				$path->toString(),
			);
		}
		$seenUpdateOrDelete[$hash] = $operation;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseRelatedItem(
		CollectionInterface $collection,
		array $input,
		PayloadPath $path,
		bool $forceNew = false,
	): RelatedItemInput {
		[$scalars, $relations] = MutationInput::splitNodeInput($collection, $input);
		$identity = PrimaryKey::of($collection)->extractFromInput($scalars);
		$key = null;
		if ($identity !== null) {
			$key = $identity;
			if (! $forceNew) {
				foreach ($identity->getValues() as $fieldName => $_) {
					unset($scalars[$fieldName]);
				}
			}
		}

		return new RelatedItemInput(
			identity: $key,
			values: $scalars,
			relations: $this->parseRelations($collection, $relations, $path),
			path: $path,
			forceNew: $forceNew,
		);
	}

	private function parseRelatedItemOrIdentity(
		CollectionInterface $collection,
		mixed $item,
		PayloadPath $path,
	): RelatedItemInput {
		if (! is_array($item)) {
			$identity = $this->identityFromScalar($collection, $item, $path);

			return new RelatedItemInput($identity, [], [], $path);
		}

		if (! MutationInput::isAssociativeArray($item)) {
			throw RestApiError::validationFailed([
				$path->toString() => ['Related item must be an identity or an object.'],
			]);
		}

		return $this->parseRelatedItem($collection, $item, $path);
	}

	private function identityFromMixed(CollectionInterface $collection, mixed $item, PayloadPath $path): Key
	{
		if (is_array($item)) {
			$identity = PrimaryKey::of($collection)->extractFromInput($item);
			if ($identity === null) {
				throw RestApiError::validationFailed([
					$path->toString() => ['Delete payloads require an identity.'],
				]);
			}

			return $identity;
		}

		return $this->identityFromScalar($collection, $item, $path);
	}

	private function identityFromScalar(CollectionInterface $collection, mixed $value, PayloadPath $path): Key
	{
		try {
			return PrimaryKey::of($collection)->getValue($value);
		} catch (Throwable) {
			throw RestApiError::validationFailed([
				$path->toString() !== '' ? $path->toString() : '_root' => ['Invalid related item identity.'],
			]);
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function isDetailedPayload(array $input): bool
	{
		foreach (self::DETAILED_KEYS as $key) {
			if (array_key_exists($key, $input)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	private function mergeFiles(CollectionInterface $collection, array $input, array $files): array
	{
		foreach ($files as $field => $file) {
			$name = (string) $field;
			if (! $collection->fields->has($name) && ! $collection->relations->has($name)) {
				continue;
			}

			if ($file instanceof UploadedFileInterface || is_array($file)) {
				$input[$name] = $file;
			}
		}

		return $input;
	}
}
