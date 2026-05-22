<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\FilterNode;
interface DataSourceInterface
{
	/**
	 * Create a new item. Returns the created item as an associative array.
	 */
	public function create(CollectionInterface $collection, array $input): ?array;

	/**
	 * Update items matching criteria. Returns the updated item when criteria
	 * identifies a single item, or null when no item can be returned.
	 */
	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array;

	/**
	 * Delete items matching criteria. Returns true if at least one row was deleted.
	 */
	public function delete(CollectionInterface $collection, FilterNode $criteria): bool;

	/**
	 * Run a group of writes atomically.
	 */
	public function transaction(callable $callback): mixed;

	/**
	 * Clear internal caches (DataLoader state). Called after each request.
	 */
	public function clearCache(): void;
}
