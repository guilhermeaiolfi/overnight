<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use ON\GraphQL\Error\GraphQLUserError;
use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;

class CycleResolver implements GraphQLResolverInterface
{
	public function __construct(
		protected ORMInterface $orm,
		protected Registry $registry
	) {
	}

	public function resolveCollection(Collection $collection, array $args = []): array
	{
		$role = $collection->getName();
		$repo = $this->orm->getRepository($role);

		$scope = $this->buildScope($collection, $args);
		$items = iterator_to_array($repo->findAll($scope));

		$totalCount = count($items);

		if (isset($args['offset'])) {
			$items = array_slice($items, (int) $args['offset']);
		}
		if (isset($args['limit'])) {
			$items = array_slice($items, 0, (int) $args['limit']);
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
			$entityClass = $collection->getEntity();
			$entity = new $entityClass();

			foreach ($input as $key => $value) {
				$entity->{$key} = $value;
			}

			$em = new EntityManager($this->orm);
			$em->persist($entity);
			$em->run();

			return $entity;
		} catch (\Throwable $e) {
			throw new GraphQLUserError('Failed to create record: ' . $e->getMessage(), 'DATABASE_ERROR', null, $e);
		}
	}

	public function resolveUpdate(Collection $collection, string $id, array $input): ?object
	{
		try {
			$entity = $this->resolveById($collection, $id);

			if ($entity === null) {
				return null;
			}

			foreach ($input as $key => $value) {
				$entity->{$key} = $value;
			}

			$em = new EntityManager($this->orm);
			$em->persist($entity);
			$em->run();

			return $entity;
		} catch (\Throwable $e) {
			throw new GraphQLUserError('Failed to update record: ' . $e->getMessage(), 'DATABASE_ERROR', null, $e);
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
		} catch (\Throwable $e) {
			throw new GraphQLUserError('Failed to delete record: ' . $e->getMessage(), 'DATABASE_ERROR', null, $e);
		}
	}

	public function resolveNestedCreate(Collection $collection, array $input, array $nestedInput): ?object
	{
		try {
			$entityClass = $collection->getEntity();
			$entity = new $entityClass();

			foreach ($input as $key => $value) {
				$entity->{$key} = $value;
			}

			$em = new EntityManager($this->orm);
			$em->persist($entity);
			$em->run();

			// Process nested relations
			foreach ($nestedInput as $relationName => $relationData) {
				if (!$collection->relations->has($relationName)) {
					continue;
				}

				$relation = $collection->relations->get($relationName);
				$targetCollection = $this->registry->getCollection($relation->getCollection());

				if ($targetCollection === null) {
					continue;
				}

				$innerKey = $relation->getInnerKey();
				$outerKey = $relation->getOuterKey();
				$foreignKeyValue = $entity->{$outerKey} ?? null;
				$targetEntityClass = $targetCollection->getEntity();

				if ($relation instanceof HasManyRelation) {
					$em2 = new EntityManager($this->orm);
					foreach ($relationData as $childInput) {
						$child = new $targetEntityClass();
						$childInput[$innerKey] = $foreignKeyValue;
						foreach ($childInput as $key => $value) {
							$child->{$key} = $value;
						}
						$em2->persist($child);
					}
					$em2->run();
				} elseif ($relation instanceof HasOneRelation) {
					$child = new $targetEntityClass();
					$relationData[$innerKey] = $foreignKeyValue;
					foreach ($relationData as $key => $value) {
						$child->{$key} = $value;
					}
					$em2 = new EntityManager($this->orm);
					$em2->persist($child);
					$em2->run();
				}
			}

			return $entity;
		} catch (\Throwable $e) {
			throw new GraphQLUserError('Failed to create record with relations: ' . $e->getMessage(), 'DATABASE_ERROR', null, $e);
		}
	}

	public function clearCache(): void
	{
		// No internal cache to clear
	}

	public function resolveRelation(mixed $source, RelationInterface $relation): mixed
	{
		// Check if the relation data is already loaded on the entity (eager loading)
		$relationName = $relation->getName();

		if (isset($source->{$relationName})) {
			$value = $source->{$relationName};

			if ($relation instanceof HasOneRelation) {
				return is_array($value) ? ($value[0] ?? null) : $value;
			}

			if ($relation instanceof HasManyRelation) {
				return is_iterable($value) ? iterator_to_array($value) : [];
			}

			return $value;
		}

		// Fallback: query the relation via repository
		$targetCollection = $this->registry->getCollection($relation->getCollection());

		if ($targetCollection === null) {
			return $relation instanceof HasOneRelation ? null : [];
		}

		$outerKey = $relation->getOuterKey();
		$innerKey = $relation->getInnerKey();
		$role = $targetCollection->getName();

		$sourceId = is_array($source)
			? ($source[$outerKey] ?? null)
			: ($source->{$outerKey} ?? null);

		if ($sourceId === null) {
			return $relation instanceof HasOneRelation ? null : [];
		}

		$repo = $this->orm->getRepository($role);
		$results = iterator_to_array($repo->findAll([$innerKey => $sourceId]));

		if ($relation instanceof HasOneRelation) {
			return $results[0] ?? null;
		}

		return $results;
	}

	protected function buildScope(Collection $collection, array $args): array
	{
		$scope = [];

		foreach ($collection->fields as $name => $field) {
			if ($field->isPrimaryKey() || !$field->isFilterable()) {
				continue;
			}

			if (!isset($args[$name])) {
				continue;
			}

			$scope[$field->getColumn()] = $args[$name];
		}

		return $scope;
	}
}
