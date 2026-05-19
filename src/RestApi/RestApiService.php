<?php

declare(strict_types=1);

namespace ON\RestApi;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\AuthorizationAwareEventInterface;
use ON\RestApi\Event\FileUpload;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemDelete;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Event\ItemUpdate;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Resolver\DataSourceInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiService
{
	public function __construct(
		protected Registry $registry,
		protected DataSourceInterface $resolver,
		protected ?EventDispatcherInterface $eventDispatcher = null
	) {
	}

	public function getResolver(): DataSourceInterface
	{
		return $this->resolver;
	}

	public function getCollection(string $collectionName): CollectionInterface
	{
		$collection = $this->registry->getCollection($collectionName);

		if ($collection === null || $collection->isHidden()) {
			throw RestApiError::collectionNotFound($collectionName);
		}

		return $collection;
	}

	public function getCollections(): array
	{
		return $this->registry->getCollections();
	}

	public function getPrimaryKeyName(string|CollectionInterface $collection): string
	{
		$collection = $this->resolveCollection($collection);
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getName();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getName();
		}

		return 'id';
	}

	public function list(string|CollectionInterface $collection, QuerySpec $querySpec, array $options = []): array
	{
		$collection = $this->resolveCollection($collection);
		$params = $this->eventParams($options, $querySpec);

		if ($this->shouldDispatchEvents($params)) {
			$event = new ItemList($collection, $params);
			$this->dispatchEvent($event);
			$this->assertAuthorized($event);
			$querySpec = $event->getQuerySpec() ?? $querySpec;

			if ($event->isDefaultPrevented()) {
				return [
					'items' => $event->getResult() ?? [],
					'meta' => $event->getTotalCount() === null ? [] : ['filter_count' => $event->getTotalCount()],
				];
			}
		}

		$result = $this->resolver->list($collection, $querySpec);
		if (isset($event)) {
			$event->setResult($result['items'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		return $result;
	}

	public function get(string|CollectionInterface $collection, string $id, ?QuerySpec $querySpec = null, array $options = []): ?array
	{
		$collection = $this->resolveCollection($collection);
		$params = $querySpec === null ? $options : $this->eventParams($options, $querySpec);

		if (! $this->shouldDispatchEvents($params)) {
			return $this->resolver->get($collection, $id, $querySpec);
		}

		$event = new ItemGet($collection, $id, $params);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;

		if ($event->isDefaultPrevented()) {
			return $event->getResult();
		}

		$event->setResult($this->resolver->get($collection, $id, $querySpec));
		return $event->getResult();
	}

	public function create(string|CollectionInterface $collection, array $input, array $options = []): array
	{
		$collection = $this->resolveCollection($collection);
		$dispatchEvents = $this->shouldDispatchEvents($options);

		return $this->resolver->transaction(
			fn() => $this->saveNode('create', $collection, $input, null, [], $collection, $input, $dispatchEvents, $options['files'] ?? [])
		);
	}

	public function update(string|CollectionInterface $collection, string $id, array $input, array $options = []): ?array
	{
		$collection = $this->resolveCollection($collection);
		$this->checkIfMatch($collection, $id, $options['ifMatch'] ?? null);
		$dispatchEvents = $this->shouldDispatchEvents($options);

		return $this->resolver->transaction(
			fn() => $this->saveNode('update', $collection, $input, $id, [], $collection, $input, $dispatchEvents, $options['files'] ?? [])
		);
	}

	public function upsert(string|CollectionInterface $collection, array $input, array $options = []): array
	{
		$collection = $this->resolveCollection($collection);
		$id = $this->inputPrimaryKeyValue($collection, $input);
		if ($id === null) {
			$primaryKey = $this->getPrimaryKeyName($collection);
			throw new RestApiError(
				"Upsert requires primary key '{$primaryKey}'.",
				'MISSING_PRIMARY_KEY',
				$primaryKey,
				400
			);
		}

		$dispatchEvents = $this->shouldDispatchEvents($options);

		return $this->resolver->transaction(
			fn() => $this->saveNode('upsert', $collection, $input, (string) $id, [], $collection, $input, $dispatchEvents, $options['files'] ?? []) ?? []
		);
	}

	public function delete(string|CollectionInterface $collection, string $id, array $options = []): bool
	{
		$collection = $this->resolveCollection($collection);
		$this->checkIfMatch($collection, $id, $options['ifMatch'] ?? null);

		if (! $this->shouldDispatchEvents($options)) {
			return $this->resolver->delete($collection, $id);
		}

		$event = new ItemDelete($collection, $id);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);

		if ($event->isDefaultPrevented()) {
			return true;
		}

		return $this->resolver->delete($collection, $id);
	}

	public function aggregate(string|CollectionInterface $collection, QuerySpec $querySpec, array $options = []): array
	{
		$collection = $this->resolveCollection($collection);
		$params = $this->eventParams($options, $querySpec);

		if (! $this->shouldDispatchEvents($params)) {
			return $this->resolver->aggregate($collection, $querySpec);
		}

		$event = new ItemList($collection, $params);
		$this->dispatchEvent($event);
		$this->assertAuthorized($event);
		$querySpec = $event->getQuerySpec() ?? $querySpec;

		if ($event->isDefaultPrevented()) {
			return $event->getResult() ?? [];
		}

		$result = $this->resolver->aggregate($collection, $querySpec);
		$event->setResult($result);

		return $event->getResult() ?? [];
	}

	public function clearCache(): void
	{
		$this->resolver->clearCache();
	}

	public function computeETag(string $jsonBody): string
	{
		return 'W/"' . md5($jsonBody) . '"';
	}

	public function getItemETag(string|CollectionInterface $collection, string $id): string
	{
		$collection = $this->resolveCollection($collection);
		$current = $this->get($collection, $id, null, ['dispatchEvents' => false]);

		if ($current === null) {
			throw RestApiError::notFound();
		}

		return $this->computeETag(json_encode(['data' => $current]));
	}

	protected function resolveCollection(string|CollectionInterface $collection): CollectionInterface
	{
		return is_string($collection) ? $this->getCollection($collection) : $collection;
	}

	/**
	 * @param 'create'|'update'|'upsert' $mode
	 */
	protected function saveNode(
		string $mode,
		CollectionInterface $collection,
		array $input,
		?string $id,
		array $path,
		CollectionInterface $rootCollection,
		array $rootInput,
		bool $dispatchEvents,
		array $files = []
	): ?array {
		$input = $this->handleFileUploads($collection, $input, $files);
		$id ??= $this->inputPrimaryKeyValue($collection, $input);
		$id = $id === null ? null : (string) $id;
		$operation = $mode;

		if ($mode === 'upsert') {
			$operation = $id !== null && $this->resolver->get($collection, $id) !== null
				? 'update'
				: 'create';
		}

		if ($operation === 'update' && $id === null) {
			return null;
		}

		if ($dispatchEvents) {
			$event = $operation === 'create'
				? new ItemCreate($collection, $input, $path, $rootCollection, $rootInput)
				: new ItemUpdate($collection, $id, $input, $path, $rootCollection, $rootInput);
			$this->dispatchEvent($event);
			$this->assertAuthorized($event);
			$input = $event->getInput();

			if ($event->isDefaultPrevented()) {
				return $event instanceof ItemCreate ? ($event->getResult() ?? []) : $event->getResult();
			}
		}

		if ($operation === 'create') {
			$createId = $this->inputPrimaryKeyValue($collection, $input);
			if ($createId !== null && $this->resolver->get($collection, (string) $createId) !== null) {
				throw new RestApiError(
					"A record with this {$this->getPrimaryKeyName($collection)} already exists.",
					'DUPLICATE',
					$this->getPrimaryKeyName($collection),
					409
				);
			}
		}

		[$scalarInput, $relations] = $this->splitNodeInput($collection, $input);
		$beforeParent = [];
		$afterParent = [];

		foreach ($relations as $relationName => $relationInput) {
			$relation = $collection->relations->get($relationName);
			if ($this->relationBelongsOnParent($collection, $relation)) {
				$beforeParent[$relationName] = $relationInput;
				continue;
			}

			$afterParent[$relationName] = $relationInput;
		}

		foreach ($beforeParent as $relationName => $relationInput) {
			$relation = $collection->relations->get($relationName);
			$targetCollection = $relation->getCollection();
			$innerField = $relation->getInnerField()->getName();
			$relationFiles = is_array($files[$relationName] ?? null) ? $files[$relationName] : [];

			if (is_array($relationInput) && $this->isAssociativeArray($relationInput)) {
				$targetId = $this->inputPrimaryKeyValue($targetCollection, $relationInput);
				$target = $this->saveNode(
					'upsert',
					$targetCollection,
					$relationInput,
					$targetId === null ? null : (string) $targetId,
					[...$path, $relationName],
					$rootCollection,
					$rootInput,
					$dispatchEvents,
					$relationFiles
				);

				if ($target !== null) {
					$scalarInput[$innerField] = $this->targetKeyValue($targetCollection, $target, $relation->getOuterField()->getName());
				}
			} elseif (!is_array($relationInput)) {
				$scalarInput[$innerField] = $relationInput;
			}
		}

		$saved = $operation === 'create'
			? $this->resolver->create($collection, $scalarInput)
			: $this->resolver->update($collection, $id, $scalarInput);

		if ($saved === null) {
			return null;
		}

		$saved = $this->persistAfterParentRelations(
			$collection,
			$saved,
			$afterParent,
			$path,
			$rootCollection,
			$rootInput,
			$dispatchEvents,
			$files
		);

		if (isset($event)) {
			$event->setResult($saved);
			return $event instanceof ItemCreate ? ($event->getResult() ?? $saved) : $event->getResult();
		}

		return $saved;
	}

	protected function persistAfterParentRelations(
		CollectionInterface $collection,
		array $parent,
		array $relations,
		array $path,
		CollectionInterface $rootCollection,
		array $rootInput,
		bool $dispatchEvents,
		array $files = []
	): array {
		if ($relations === []) {
			return $parent;
		}

		$parentId = $this->targetKeyValue($collection, $parent, $this->getPrimaryKeyName($collection));

		foreach ($relations as $relationName => $relationInput) {
			$relation = $collection->relations->get($relationName);
			$targetCollection = $relation->getCollection();
			$relationFiles = is_array($files[$relationName] ?? null) ? $files[$relationName] : [];

			if ($relation->isJunction()) {
				$this->persistManyToManyRelation(
					$collection,
					$targetCollection,
					$relationName,
					(string) $parentId,
					$relationInput,
					[...$path, $relationName],
					$rootCollection,
					$rootInput,
					$dispatchEvents,
					$relationFiles
				);
				continue;
			}

			$items = $this->normalizeRelationItems($relationInput);
			foreach ($items as $index => $item) {
				if (!is_array($item)) {
					continue;
				}

				$item[$relation->getOuterField()->getName()] = $this->targetKeyValue($collection, $parent, $relation->getInnerField()->getName());
				$itemPath = $relation->getCardinality() === 'many'
					? [...$path, $relationName, $index]
					: [...$path, $relationName];
				$itemId = $this->inputPrimaryKeyValue($targetCollection, $item);
				$itemFiles = $relation->getCardinality() === 'many'
					? (is_array($relationFiles[$index] ?? null) ? $relationFiles[$index] : [])
					: $relationFiles;

				$this->saveNode(
					'upsert',
					$targetCollection,
					$item,
					$itemId === null ? null : (string) $itemId,
					$itemPath,
					$rootCollection,
					$rootInput,
					$dispatchEvents,
					$itemFiles
				);
			}
		}

		return $this->resolver->get($collection, (string) $parentId) ?? $parent;
	}

	protected function persistManyToManyRelation(
		CollectionInterface $collection,
		CollectionInterface $targetCollection,
		string $relationName,
		string $parentId,
		mixed $operations,
		array $path,
		CollectionInterface $rootCollection,
		array $rootInput,
		bool $dispatchEvents,
		array $files = []
	): void {
		if (!is_array($operations)) {
			$this->resolver->connectManyToMany($collection, $parentId, $relationName, $operations);
			return;
		}

		if (!$this->isAssociativeArray($operations)) {
			foreach ($operations as $targetId) {
				$this->resolver->connectManyToMany($collection, $parentId, $relationName, $targetId);
			}

			return;
		}

		foreach ($operations['disconnect'] ?? [] as $targetId) {
			$this->resolver->disconnectManyToMany($collection, $parentId, $relationName, $targetId);
		}

		foreach ($operations['connect'] ?? [] as $targetId) {
			$this->resolver->connectManyToMany($collection, $parentId, $relationName, $targetId);
		}

		foreach ($this->normalizeRelationItems($operations['create'] ?? []) as $index => $item) {
			if (!is_array($item)) {
				continue;
			}

			$created = $this->saveNode(
				'create',
				$targetCollection,
				$item,
				null,
				[...$path, 'create', $index],
				$rootCollection,
				$rootInput,
				$dispatchEvents,
				is_array($files['create'][$index] ?? null) ? $files['create'][$index] : []
			);
			$this->resolver->connectManyToMany(
				$collection,
				$parentId,
				$relationName,
				$this->targetKeyValue($targetCollection, $created, $this->getPrimaryKeyName($targetCollection))
			);
		}
	}

	/**
	 * @return array{0: array, 1: array}
	 */
	protected function splitNodeInput(CollectionInterface $collection, array $input): array
	{
		$scalar = [];
		$relations = [];

		foreach ($input as $key => $value) {
			if ($collection->relations->has((string) $key)) {
				$relations[(string) $key] = $value;
				continue;
			}

			$scalar[$key] = $value;
		}

		return [$scalar, $relations];
	}

	protected function relationBelongsOnParent(CollectionInterface $collection, RelationInterface $relation): bool
	{
		return !$relation->isJunction() && $relation->getInnerField()->getColumn() !== $this->getPrimaryKeyColumn($collection);
	}

	protected function normalizeRelationItems(mixed $input): array
	{
		if (!is_array($input)) {
			return [];
		}

		return $this->isAssociativeArray($input) ? [$input] : $input;
	}

	protected function inputPrimaryKeyValue(CollectionInterface $collection, array $input): mixed
	{
		$primaryKey = $this->getPrimaryKeyName($collection);
		if (array_key_exists($primaryKey, $input)) {
			return $input[$primaryKey];
		}

		return null;
	}

	protected function targetKeyValue(CollectionInterface $collection, array $row, string $fieldOrColumn): mixed
	{
		if (array_key_exists($fieldOrColumn, $row)) {
			return $row[$fieldOrColumn];
		}

		if ($collection->fields->has($fieldOrColumn)) {
			$column = $collection->fields->get($fieldOrColumn)->getColumn();
			return $row[$column] ?? null;
		}

		if ($collection->fields->hasColumn($fieldOrColumn)) {
			$fieldName = $collection->fields->getKeyByColumnName($fieldOrColumn);
			return $row[$fieldName] ?? null;
		}

		return null;
	}

	protected function columnToFieldName(CollectionInterface $collection, string $column): string
	{
		if ($collection->fields->hasColumn($column)) {
			return $collection->fields->getKeyByColumnName($column);
		}

		throw RestApiError::invalidField($column);
	}

	protected function getPrimaryKeyColumn(CollectionInterface $collection): string
	{
		$pk = $collection->getPrimaryKey();

		if ($pk instanceof FieldInterface) {
			return $pk->getColumn();
		}

		if (is_array($pk) && isset($pk[0]) && $pk[0] instanceof FieldInterface) {
			return $pk[0]->getColumn();
		}

		return 'id';
	}

	protected function isAssociativeArray(array $value): bool
	{
		if ($value === []) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}

	public function dispatchEvent(object $event): void
	{
		$this->eventDispatcher?->dispatch($event);
	}

	protected function assertAuthorized(object $event): void
	{
		if (! $event instanceof AuthorizationAwareEventInterface) {
			return;
		}

		match ($event->getAuthState()) {
			AuthState::Allowed => null,
			AuthState::Unauthenticated => throw RestApiError::unauthenticated(),
			AuthState::Forbidden, AuthState::Pending => throw RestApiError::forbidden(),
		};
	}

	protected function checkIfMatch(CollectionInterface $collection, string $id, ?string $ifMatch): void
	{
		if ($ifMatch === null || $ifMatch === '') {
			return;
		}

		if ($ifMatch !== $this->getItemETag($collection, $id)) {
			throw RestApiError::preconditionFailed();
		}
	}

	protected function handleFileUploads(CollectionInterface $collection, array $input, array $files): array
	{
		if ($files === []) {
			return $input;
		}

		$fileFieldTypes = ['file', 'image', 'upload'];

		foreach ($collection->fields as $name => $field) {
			if (! in_array($field->getType(), $fileFieldTypes, true)) {
				continue;
			}

			if (! isset($files[$name])) {
				continue;
			}

			$event = new FileUpload($collection, $name, $files[$name]);
			$this->dispatchEvent($event);

			if ($event->getStoredValue() !== null) {
				$input[$name] = $event->getStoredValue();
			} else {
				throw RestApiError::fileHandlerMissing($name);
			}
		}

		return $input;
	}

	protected function eventParams(array $params, QuerySpec $querySpec): array
	{
		$params['querySpec'] = $querySpec;

		return $params;
	}

	protected function shouldDispatchEvents(array $options): bool
	{
		return $this->eventDispatcher !== null && ($options['dispatchEvents'] ?? true);
	}
}
