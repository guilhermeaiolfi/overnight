<?php

declare(strict_types=1);

namespace ON\RestApi\Repository;

use ON\Data\Query\SelectQuery;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\RepresentationInterface;
use ON\RestApi\Support\PrimaryKeyValue;

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

	/**
	 * @param class-string<RepresentationInterface> $output
	 * @return array<string, mixed>|null
	 */
	public function findByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		string $output = PhpRepresentation::class,
	): ?array;

	public function create(CollectionInterface $collection, array $input): ?array;

	public function update(CollectionInterface $collection, array $criteria, array $input): ?array;

	public function delete(CollectionInterface $collection, array $criteria): bool;
}
