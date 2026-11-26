<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use Cycle\ORM\EntityManager;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Reference\Promise;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;
use Cycle\ORM\Transaction\Runner;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;
use ON\GraphQL\Error\GraphQLUserError;
use ReflectionMethod;
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
		$orderBy = $this->buildOrderBy($collection, $args);

		if (
			$repo instanceof Repository
			&& (new ReflectionMethod($repo, 'findAll'))->getDeclaringClass()->getName() === Repository::class
		) {
			$select = $repo->select();
			$this->applyWhere($select, $scope);

			$totalCount = $select->count();

			if ($orderBy !== []) {
				$select->orderBy($orderBy);
			}

			if ($limit !== null) {
				$select->limit($limit);
			}

			if ($offset !== null) {
				$select->offset($offset);
			}

			$items = $limit === 0 ? [] : $this->asList($select->fetchAll());

			return [
				'items' => $items,
				'totalCount' => $totalCount,
			];
		}

		// Custom repositories receive the same physical field names as the legacy findAll path.
		$repositoryScope = [];
		foreach ($scope as $name => $value) {
			$repositoryScope[$collection->getField($name)?->getColumn() ?? $name] = $value;
		}

		$results = $repo instanceof Repository
			? $repo->findAll($repositoryScope, $orderBy)
			: $repo->findAll($repositoryScope);
		$items = $this->asList($results);
		$totalCount = count($items);

		if (! $repo instanceof Repository && $orderBy !== []) {
			$field = array_key_first($orderBy);
			$direction = $orderBy[$field] === 'DESC' ? -1 : 1;
			$mapper = $this->orm->getMapper($role);
			usort($items, static function (object $left, object $right) use ($field, $direction, $mapper): int {
				$leftFields = $mapper->fetchFields($left);
				$rightFields = $mapper->fetchFields($right);

				return $direction * (($leftFields[$field] ?? null) <=> ($rightFields[$field] ?? null));
			});
		}

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
		return $this->createNested($collection, $input, $nestedInput, null);
	}

	public function resolveNestedCreateWithChildPreparation(
		Collection $collection,
		array $input,
		array $nestedInput,
		callable $prepareChild,
	): ?object {
		return $this->createNested($collection, $input, $nestedInput, $prepareChild);
	}

	private function createNested(
		Collection $collection,
		array $input,
		array $nestedInput,
		?callable $prepareChild,
	): ?object {
		$relations = NestedCreateRelations::resolve($collection, $nestedInput);
		$created = [];

		try {
			$database = $this->orm->getSource($collection->getName())->getDatabase();

			return $database->transaction(function () use ($collection, $input, $nestedInput, $relations, $database, &$created, $prepareChild): object {
				$entity = $created[] = $this->makeEntity($collection, $input);
				$em = new EntityManager($this->orm);
				$em->persist($entity);
				$em->run(runner: Runner::outerTransaction());

				$childrenEm = new EntityManager($this->orm);
				$parentFields = $this->orm->getMapper($entity)->fetchFields($entity);
				$writtenRelations = [];

				foreach ($relations as $relationName => $relation) {
					$relationData = $nestedInput[$relationName];
					$targetCollection = $relation->getCollection();

					$targetDatabase = $this->orm->getSource($targetCollection->getName())->getDatabase();
					if ($database->getDriver() !== $targetDatabase->getDriver()) {
						throw new GraphQLUserError('Nested writes require the same database connection.', 'INVALID_INPUT');
					}

					$innerKey = $relation->getInnerField()->getName();
					$outerKey = $relation->getOuterField()->getName();
					// Cycle convention: innerKey is on source (parent), outerKey is on target (child)
					$parentKeyValue = $parentFields[$innerKey] ?? null;

					$children = $relation->getCardinality()->isMany() ? $relationData : [$relationData];
					foreach ($children as $childIndex => $childInput) {
						if (! is_array($childInput)) {
							continue;
						}
						if ($parentKeyValue === null) {
							throw new GraphQLUserError('Nested create requires a parent relation key.', 'INVALID_INPUT', $relation->getName());
						}
						$effectiveInput = array_replace($childInput, [$outerKey => $parentKeyValue]);
						if ($prepareChild !== null) {
							$prepareChild($relationName, $childIndex, $relation, $effectiveInput);
						}

						$child = $created[] = $this->makeEntity($targetCollection, $effectiveInput, [$outerKey => $parentKeyValue]);
						$childrenEm->persist($child);
						$writtenRelations[$relationName] = true;
					}
				}

				if (count($created) > 1) {
					$childrenEm->run(runner: Runner::outerTransaction());
					$this->refreshRelationReferences($entity, array_keys($writtenRelations));
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
		$isSingle = $relation->getCardinality()->isSingle();
		if ($relation->getCollection()->isHidden() || (! is_array($source) && ! is_object($source))) {
			return $isSingle ? null : [];
		}

		$relationName = $relation->getName();
		$node = is_object($source) ? $this->orm->getHeap()->get($source) : null;
		$values = $node !== null
			? $this->orm->getMapper($source)->fetchRelations($source)
			: (is_array($source) ? $source : get_object_vars($source));
		$value = $values[$relationName] ?? null;

		if ($value instanceof Promise) {
			$value = $value->fetch();
		} elseif ($value instanceof ReferenceInterface || ! array_key_exists($relationName, $values)) {
			$role = $node?->getRole() ?? $relation->getParent()->getName();
			$mapping = $this->orm->getRelationMap($role)->getRelations()[$relationName] ?? null;
			if ($mapping !== null) {
				$reference = $value instanceof ReferenceInterface
					? $value
					: $mapping->initReference($node ?? new Node(Node::MANAGED, $values, $role));
				$value = $mapping->collect($mapping->resolve($reference, true));
			}
		}

		if ($isSingle) {
			return is_array($value) ? ($value === [] ? null : reset($value)) : $value;
		}

		return is_iterable($value) ? $this->asList($value) : [];
	}

	private function asList(iterable $values): array
	{
		return is_array($values) ? array_values($values) : iterator_to_array($values, false);
	}

	/** @param list<string> $names */
	private function refreshRelationReferences(object $entity, array $names): void
	{
		$node = $this->orm->getHeap()->get($entity);
		if ($node === null) {
			return;
		}

		$mappings = $this->orm->getRelationMap($node->getRole())->getRelations();
		$references = [];
		foreach ($names as $name) {
			if (isset($mappings[$name])) {
				$references[$name] = $mappings[$name]->initReference($node);
				$node->setRelation($name, $references[$name]);
			}
		}
		$this->orm->getMapper($entity)->hydrate($entity, $references);
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
