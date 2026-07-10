<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Support\MutationInput;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\PrimaryKeyValue;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Directus wire-format parser → compact normalized mutation model.
 * Does not query, plan, or persist.
 */
final class DirectusPayloadParser
{
	private const DETAILED_KEYS = ['create', 'update', 'delete', 'connect', 'disconnect'];
	private const DIRECTUS_DETAILED_KEYS = ['create', 'update', 'delete'];

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
		foreach ($items as $index => $item) {
			$parsedItems[] = $this->parseRelatedItemOrIdentity(
				$relation->getCollection(),
				$item,
				$path->append((int) $index),
			);
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
		$unlink = [];

		foreach (MutationInput::normalizeRelationItems($input['create'] ?? []) as $index => $item) {
			if (! is_array($item)) {
				throw RestApiError::validationFailed([
					$path->append('create')->append((int) $index)->toString() => ['Create payloads must be objects.'],
				]);
			}
			$create[] = $this->parseRelatedItem($target, $item, $path->append('create')->append((int) $index), forceNew: true);
		}

		foreach (MutationInput::normalizeRelationItems($input['update'] ?? []) as $index => $item) {
			if (! is_array($item)) {
				throw RestApiError::validationFailed([
					$path->append('update')->append((int) $index)->toString() => ['Update payloads must be objects with an identity.'],
				]);
			}
			$related = $this->parseRelatedItem($target, $item, $path->append('update')->append((int) $index));
			if ($related->identity === null) {
				throw RestApiError::validationFailed([
					$path->append('update')->append((int) $index)->toString() => ['Update payloads require an identity.'],
				]);
			}
			$update[] = $related;
		}

		foreach (MutationInput::normalizeRelationItems($input['delete'] ?? []) as $index => $item) {
			$delete[] = $this->identityFromMixed($target, $item, $path->append('delete')->append((int) $index));
		}

		// Overnight extension beyond Directus: connect assigns existing identities; disconnect unlinks.
		foreach (MutationInput::normalizeRelationItems($input['connect'] ?? []) as $index => $item) {
			$create[] = $this->parseRelatedItemOrIdentity(
				$target,
				$item,
				$path->append('connect')->append((int) $index),
			);
		}

		foreach (MutationInput::normalizeRelationItems($input['disconnect'] ?? []) as $index => $item) {
			$unlink[] = $this->identityFromMixed($target, $item, $path->append('disconnect')->append((int) $index));
		}

		foreach (array_keys($input) as $key) {
			if (! in_array((string) $key, self::DETAILED_KEYS, true)) {
				throw RestApiError::validationFailed([
					$path->toString() => [sprintf("Unknown detailed relation operation '%s'.", (string) $key)],
				]);
			}
		}

		return new ToManyExplicitMutation($relation, $path, $create, $update, $delete, $unlink);
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
			$key = $this->toKey($collection, $identity);
			if (! $forceNew) {
				foreach ($identity->values() as $fieldName => $_) {
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

			return $this->toKey($collection, $identity);
		}

		return $this->identityFromScalar($collection, $item, $path);
	}

	private function identityFromScalar(CollectionInterface $collection, mixed $value, PayloadPath $path): Key
	{
		try {
			$identity = PrimaryKey::of($collection)->getValue($value);

			return $this->toKey($collection, $identity);
		} catch (\Throwable) {
			throw RestApiError::validationFailed([
				$path->toString() !== '' ? $path->toString() : '_root' => ['Invalid related item identity.'],
			]);
		}
	}

	private function toKey(CollectionInterface $collection, PrimaryKeyValue $identity): Key
	{
		/** @var non-empty-array<string, string|int|float|bool> $values */
		$values = [];
		foreach ($identity->values() as $fieldName => $value) {
			if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
				throw RestApiError::validationFailed([
					(string) $fieldName => ['Primary key values must be scalar.'],
				]);
			}
			$values[(string) $fieldName] = $value;
		}

		return new Key($collection, $values);
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
