<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Reference\Promise;
use Cycle\ORM\Select;
use Cycle\ORM\Transaction\Runner;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;
use ON\GraphQL\Error\GraphQLUserError;
use Throwable;

class CycleResolver implements GraphQLResolverInterface
{
	public function __construct(
		protected ORMInterface $orm,
		protected Registry $registry,
		protected int $defaultLimit = 100,
		protected int $maxLimit = 1000,
	) {
	}

	public function resolveCollection(Collection $collection, array $args = []): array
	{
		$role = $collection->getName();
		$repo = $this->orm->getRepository($role);
		$scope = $this->buildScope($collection, $args);
		[$limit, $offset] = $this->resolvePagination($args);

		if (method_exists($repo, 'select')) {
			/** @var Select $select */
			$select = $repo->select();
			$this->applyWhere($select, $scope);

			$totalCount = $select->count();

			$orderBy = $this->buildOrderBy($collection, $args);
			if ($orderBy !== []) {
				$select->orderBy($orderBy);
			}

			if ($limit !== null) {
				$select->limit($limit);
			}

			if ($offset !== null) {
				$select->offset($offset);
			}

			$items = iterator_to_array($select->fetchAll());

			return [
				'items' => $items,
				'totalCount' => $totalCount,
			];
		}

		$items = iterator_to_array($repo->findAll($scope));
		$totalCount = count($items);

		if ($offset !== null) {
			$items = array_slice($items, $offset);
		}
		if ($limit !== null) {
			$items = array_slice($items, 0, $limit);
		}

		return [
			'items' => $items,
			'totalCount' => $totalCount,
		];
	}

	public function resolveById(Collection $collection, string $id): ?object
	{
		$role = $collection->getName();

		return $this->orm->getRepository($role)->findByPK($id);
	}

	public function resolveCreate(Collection $collection, array $input): ?object
	{
		try {
			$entity = $this->makeEntity($collection, $input);
			$em = new EntityManager($this->orm);
			$em->persist($entity);
			$em->run();

			return $entity;
		} catch (GraphQLUserError $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new GraphQLUserError('Failed to create record.', 'DATABASE_ERROR', null, $e);
		}
	}

	public function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		try {
			$entity = $this->resolveById($collection, $id);

			if ($entity === null) {
				return null;
			}

			$fields = $this->writableFields($collection, $input);
			if ($fields === []) {
				return $entity;
			}

			$this->orm->getMapper($entity)->hydrate($entity, $fields);
			$em = new EntityManager($this->orm);
			$em->persist($entity);
			$em->run();

			return $entity;
		} catch (GraphQLUserError $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new GraphQLUserError('Failed to update record.', 'DATABASE_ERROR', null, $e);
		}
	}

	public function resolveDelete(Collection $collection, string $id): ?object
	{
		try {
			$entity = $this->resolveById($collection, $id);

			if ($entity === null) {
				return null;
			}

			$em = new EntityManager($this->orm);
			$em->delete($entity);
			$em->run();

			return $entity;
		} catch (GraphQLUserError $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new GraphQLUserError('Failed to delete record.', 'DATABASE_ERROR', null, $e);
		}
	}

	public function resolveNestedCreate(Collection $collection, array $input, array $nestedInput): ?object
	{
		$created = [];

		try {
			$database = $this->orm->getSource($collection->getName())->getDatabase();

			return $database->transaction(function () use ($collection, $input, $nestedInput, $database, &$created): object {
				$entity = $created[] = $this->makeEntity($collection, $input);
				$em = new EntityManager($this->orm);
				$em->persist($entity);
				$em->run(runner: Runner::outerTransaction());

				$childrenEm = new EntityManager($this->orm);

				foreach ($nestedInput as $relationName => $relationData) {
					if (! $collection->relations->has($relationName)) {
						continue;
					}

					$relation = $collection->relations->get($relationName);
					$targetCollection = $relation->getCollection();

					if ($targetCollection->isHidden()) {
						continue;
					}

					$targetDatabase = $this->orm->getSource($targetCollection->getName())->getDatabase();
					if ($database->getDriver() !== $targetDatabase->getDriver()) {
						throw new GraphQLUserError('Nested writes require the same database connection.', 'INVALID_INPUT');
					}

					$innerKey = $relation->getInnerField()->getName();
					$outerKey = $relation->getOuterField()->getName();
					// Cycle convention: innerKey is on source (parent), outerKey is on target (child)
					$parentKeyValue = $entity->{$innerKey} ?? null;

					$children = $relation->getCardinality()->isMany() ? $relationData : [$relationData];
					foreach ($children as $childInput) {
						if (! is_array($childInput)) {
							continue;
						}
						$child = $created[] = $this->makeEntity($targetCollection, $childInput, [$outerKey => $parentKeyValue]);
						$childrenEm->persist($child);
					}
				}

				if (count($created) > 1) {
					$childrenEm->run(runner: Runner::outerTransaction());
				}

				return $entity;
			});
		} catch (Throwable $e) {
			foreach ($created as $entity) {
				$this->orm->getHeap()->detach($entity);
			}

			if ($e instanceof GraphQLUserError) {
				throw $e;
			}

			throw new GraphQLUserError('Failed to create record with relations.', 'DATABASE_ERROR', null, $e);
		}
	}

	public function clearCache(): void
	{
		// No internal cache to clear
	}

	public function resolveRelation(mixed $source, RelationInterface $relation): mixed
	{
		$targetCollection = $relation->getCollection();
		$isSingle = $relation->getCardinality()->isSingle();

		if ($targetCollection->isHidden()) {
			return $isSingle ? null : [];
		}

		$relationName = $relation->getName();
		$value = is_array($source) ? ($source[$relationName] ?? null) : ($source->{$relationName} ?? null);

		if ($value instanceof Promise) {
			$value = $value->fetch();
		}
		if ($isSingle && $value !== null && $value !== []) {
			return is_array($value) ? reset($value) : $value;
		}

		if (! $isSingle && is_iterable($value)) {
			$items = iterator_to_array($value, false);
			if ($items !== []) {
				return $items;
			}
		}

		// Newly created entities can still have an empty relation initialized before their key was generated.
		$outerKey = $relation->getOuterField()->getName();
		$innerKey = $relation->getInnerField()->getName();
		$role = $targetCollection->getName();

		// Cycle convention: innerKey is on source entity, outerKey is on target entity
		$sourceId = is_array($source)
			? ($source[$innerKey] ?? null)
			: ($source->{$innerKey} ?? null);

		if ($sourceId === null) {
			return $isSingle ? null : [];
		}

		$repo = $this->orm->getRepository($role);
		if (method_exists($repo, 'select')) {
			/** @var Select $select */
			$select = $repo->select()->where($outerKey, $sourceId);

			return $isSingle ? $select->limit(1)->fetchOne() : iterator_to_array($select->fetchAll());
		}

		$results = iterator_to_array($repo->findAll([$outerKey => $sourceId]));

		if ($isSingle) {
			return $results[0] ?? null;
		}

		return $results;
	}

	protected function makeEntity(CollectionInterface $collection, array $input, array $forcedFields = []): object
	{
		$fields = array_replace($this->writableFields($collection, $input), $forcedFields);
		if ($fields === []) {
			throw new GraphQLUserError('No valid fields provided.', 'INVALID_INPUT');
		}

		return $this->orm->make($collection->getName(), $fields);
	}

	protected function writableFields(CollectionInterface $collection, array $input): array
	{
		$fields = [];

		foreach ($input as $fieldName => $value) {
			if (! is_string($fieldName) || ! $collection->fields->has($fieldName)) {
				continue;
			}

			$field = $collection->fields->get($fieldName);
			if ($field->isPrimaryKey() || $field->isHidden()) {
				continue;
			}

			$fields[$fieldName] = $value;
		}

		return $fields;
	}

	protected function buildScope(Collection $collection, array $args): array
	{
		$scope = [];

		foreach ($collection->fields as $name => $field) {
			if ($field->isPrimaryKey() || $field->isHidden() || ! $field->isFilterable()) {
				continue;
			}

			if (! isset($args[$name])) {
				continue;
			}

			$scope[$name] = $args[$name];
		}

		return $scope;
	}

	protected function applyWhere(Select $select, array $scope): void
	{
		foreach ($scope as $field => $value) {
			if (is_string($value) && str_contains($value, '%')) {
				$select->where($field, 'LIKE', $value);
			} else {
				$select->where($field, $value);
			}
		}
	}

	/**
	 * @return array<string, 'ASC'|'DESC'>
	 */
	protected function buildOrderBy(Collection $collection, array $args): array
	{
		$sort = $args['sort'] ?? null;
		$order = $args['order'] ?? 'ASC';

		if (! is_string($sort) || $sort === '' || ! $collection->fields->has($sort)) {
			return [];
		}

		$field = $collection->fields->get($sort);
		if ($field->isHidden()) {
			return [];
		}

		$direction = is_string($order) && strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

		return [$sort => $direction];
	}

	/**
	 * @return array{0: ?int, 1: ?int}
	 */
	protected function resolvePagination(array $args): array
	{
		$limit = null;

		if (isset($args['limit'])) {
			$limit = max(0, (int) $args['limit']);
			if ($this->maxLimit > 0) {
				$limit = min($limit, $this->maxLimit);
			}
		} elseif ($this->defaultLimit > 0) {
			$limit = $this->maxLimit > 0
				? min($this->defaultLimit, $this->maxLimit)
				: $this->defaultLimit;
		} elseif ($this->maxLimit > 0) {
			$limit = $this->maxLimit;
		}

		$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : null;

		if ($offset !== null && $limit === null) {
			$limit = PHP_INT_MAX;
		}

		return [$limit, $offset];
	}
}
