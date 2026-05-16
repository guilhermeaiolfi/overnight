<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;

interface RestResolverInterface
{
	/**
	 * List items with filtering, sorting, pagination, field selection.
	 * Returns ['items' => array, 'meta' => array].
	 */
	public function list(CollectionInterface $collection, array $params = []): array;

	/**
	 * Get a single item by ID with optional field selection.
	 * Returns the item as an associative array, or null if not found.
	 */
	public function get(CollectionInterface $collection, string $id, array $params = []): ?array;

	/**
	 * Create a new item. Returns the created item as an associative array.
	 */
	public function create(CollectionInterface $collection, array $input): array;

	/**
	 * Update an item by ID. Returns the updated item, or null if not found.
	 */
	public function update(CollectionInterface $collection, string $id, array $input): ?array;

	/**
	 * Delete an item by ID. Returns true if deleted, false if not found.
	 */
	public function delete(CollectionInterface $collection, string $id): bool;

	/**
	 * Run aggregate queries (count, sum, avg, min, max) with optional groupBy.
	 * Returns array of aggregate result rows.
	 */
	public function aggregate(CollectionInterface $collection, array $params = []): array;

	/**
	 * Run a group of writes atomically.
	 */
	public function transaction(callable $callback): mixed;

	/**
	 * Connect a many-to-many relation row.
	 */
	public function connectManyToMany(CollectionInterface $collection, string $parentId, string $relationName, mixed $targetId): void;

	/**
	 * Disconnect a many-to-many relation row.
	 */
	public function disconnectManyToMany(CollectionInterface $collection, string $parentId, string $relationName, mixed $targetId): void;

	/**
	 * Clear internal caches (DataLoader state). Called after each request.
	 */
	public function clearCache(): void;
}
