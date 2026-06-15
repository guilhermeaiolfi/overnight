<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Service\MapperProviderInterface;
use Cycle\ORM\Service\EntityFactoryInterface;
use Cycle\ORM\Select as CycleSelect;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;

final class CycleRecordLoader
{
	public function __construct(
		private readonly ORMInterface $orm,
	) {
	}

	public function orm(): ORMInterface
	{
		return $this->orm;
	}

	public function findByIdentity(CollectionInterface $collection, PrimaryKeyValue|string $identity): ?array
	{
		return $this->findSnapshotByIdentity($collection, $identity)['data'] ?? null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function hydrateRecord(CollectionInterface $collection, array $data): object
	{
		$orm = $this->orm->withHeap(new Heap());

		return $orm
			->getService(EntityFactoryInterface::class)
			->make($collection->getName(), $data, Node::MANAGED, typecast: false);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createRecord(CollectionInterface $collection, array $data): object
	{
		$orm = $this->orm->withHeap(new Heap());

		return $orm
			->getService(EntityFactoryInterface::class)
			->make($collection->getName(), $data, Node::NEW, typecast: false);
	}

	/**
	 * @param array<string, mixed> $fieldValueMap
	 * @return list<array<string, mixed>>
	 */
	public function findRowsByFields(CollectionInterface $collection, array $fieldValueMap): array
	{
		$rows = (new CycleSelect($this->orm->withHeap(new Heap()), $collection->getName()))
			->where($fieldValueMap)
			->fetchData();

		return array_values(array_filter($rows, 'is_array'));
	}

	/**
	 * @param list<string> $relations
	 * @return array{record: object, data: array<string, mixed>}|null
	 */
	public function findSnapshotByIdentity(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		array $relations = [],
	): ?array {
		$orm = $this->orm->withHeap(new Heap());
		$primaryKey = $collection->getPrimaryKey()->getValue($identity);
		$values = array_values($primaryKey->getValues());
		$select = new CycleSelect($orm, $collection->getName());
		if ($relations !== []) {
			$select->with($relations);
		}

		$rows = $collection->getPrimaryKey()->isComposite()
			? $select->wherePK($values)->fetchData(false)
			: $select->wherePK(...$values)->fetchData(false);
		$rawRow = $rows[0] ?? null;
		if (! is_array($rawRow)) {
			return null;
		}

		$record = $orm
			->getService(EntityFactoryInterface::class)
			->make($collection->getName(), $rawRow, Node::MANAGED, typecast: true);
		$row = $orm
			->getService(MapperProviderInterface::class)
			->getMapper($collection->getName())
			->cast($rawRow);

		return [
			'record' => $record,
			'data' => $row,
		];
	}
}
