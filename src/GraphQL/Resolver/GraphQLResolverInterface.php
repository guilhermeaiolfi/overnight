<?php

declare(strict_types=1);

namespace ON\GraphQL\Resolver;

use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Relation\RelationInterface;

interface GraphQLResolverInterface
{
	/**
	 * Resolve a collection query (findAll) with filtering, sorting, pagination.
	 * Returns ['items' => [...], 'totalCount' => int].
	 */
	public function resolveCollection(Collection $collection, array $args = []): array;

	/**
	 * Resolve a single item by ID.
	 */
	public function resolveById(Collection $collection, string $id): ?object;

	/**
	 * Create a new item and return it.
	 */
	public function resolveCreate(Collection $collection, array $input): ?object;

	/**
	 * Update an item by ID and return it.
	 */
	public function resolveUpdate(Collection $collection, string $id, array $input): ?object;

	/**
	 * Delete an item by ID. Returns true if deleted.
	 */
	public function resolveDelete(Collection $collection, string $id): bool;

	/**
	 * Resolve a relation from a source object.
	 * May return a Deferred for batching.
	 */
	public function resolveRelation(mixed $source, RelationInterface $relation): mixed;
}
