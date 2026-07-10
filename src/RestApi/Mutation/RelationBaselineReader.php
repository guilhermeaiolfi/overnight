<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use function ON\Data\Query\x;
use ON\RestApi\Repository\ItemRepositoryInterface;
use stdClass;

/**
 * Loads current represented relation membership through ON\Data mutable queries.
 *
 * The baseline query runs in an isolated Session so relation sync from the read
 * does not pollute the mutation Session. Identities are re-attached with identify().
 */
final class RelationBaselineReader
{
	public function __construct(
		private readonly ItemRepositoryInterface $items,
		private readonly SessionFactory $sessions,
	) {
	}

	/**
	 * @return list<object>
	 */
	public function loadToManyIdentities(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
	): array {
		$keys = $this->loadRelatedKeys($ownerRecord, $relation);
		$target = $relation->getCollection();
		$objects = [];
		foreach ($keys as $key) {
			$objects[] = $session->identify($target, $key);
		}

		return $objects;
	}

	public function loadToOneIdentity(
		Session $session,
		RecordState $ownerRecord,
		RelationInterface $relation,
	): ?object {
		$keys = $this->loadRelatedKeys($ownerRecord, $relation);
		if ($keys === []) {
			return null;
		}

		return $session->identify($relation->getCollection(), $keys[0]);
	}

	/**
	 * @return list<Key>
	 */
	private function loadRelatedKeys(
		RecordState $ownerRecord,
		RelationInterface $relation,
	): array {
		if (! $ownerRecord->hasKey()) {
			return [];
		}

		$ownerCollection = $ownerRecord->getCollection();
		$ownerKey = $ownerRecord->getKey();
		$targetCollection = $relation->getCollection();
		$readSession = $this->sessions->create();

		$query = $this->items->select($ownerCollection, $ownerCollection->getPrimaryKey());
		foreach ($ownerKey->getValues() as $fieldName => $value) {
			$query->where(x()->eq($query->field((string) $fieldName), $value));
		}

		$pkFields = $targetCollection->getPrimaryKey();
		$query->relation($relation->getName())->fields(...$pkFields);

		$owner = $query
			->to(stdClass::class)
			->mutable($readSession)
			->fetchOne();

		if (! is_object($owner)) {
			return [];
		}

		$related = $owner->{$relation->getName()} ?? null;
		$items = match (true) {
			is_iterable($related) => $related,
			is_object($related) => [$related],
			default => [],
		};

		$keys = [];
		foreach ($items as $item) {
			if (! is_object($item)) {
				continue;
			}
			$key = $this->keyFromTracked($readSession, $item, $targetCollection->getName());
			if ($key !== null) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	private function keyFromTracked(Session $session, object $representation, string $collectionName): ?Key
	{
		$state = $session->getRepresentations()->get($representation);
		if (! $state instanceof RepresentationState) {
			return null;
		}

		try {
			$record = $session->getRecords()->getFromRepresentation($state);
		} catch (\Throwable) {
			return null;
		}

		if (! $record instanceof RecordState || ! $record->hasKey()) {
			return null;
		}

		if ($record->getCollection()->getName() !== $collectionName) {
			return null;
		}

		return $record->getKey();
	}
}
