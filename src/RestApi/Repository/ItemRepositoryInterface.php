<?php

declare(strict_types=1);

namespace ON\RestApi\Repository;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\SelectQuery;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Query\Node\FilterNode;

interface ItemRepositoryInterface
{
	public function select(CollectionInterface|string $collection, array $fieldNames = []): SelectQuery;

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchAll(SelectQuery $query): array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(SelectQuery $query): ?array;

	public function count(SelectQuery $query): int;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		bool $typed = true,
	): ?array;

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function hydrateRow(CollectionInterface $collection, array $row): array;

	public function create(CollectionInterface $collection, array $input): ?array;

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array;

	public function delete(CollectionInterface $collection, FilterNode $criteria): bool;

	public function commit(MutationQueue $queue, callable $resolve): mixed;

	/**
	 * Advanced escape hatch: direct database access bypasses the canonical model.
	 */
	public function getDatabase(): DatabaseInterface;
}
