<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\ValueRef;

/**
 * Hydrates both sides of the Cycle-backed node model:
 * - current loaded record/data for existing rows
 * - desired working record for create/update/delete planning
 */
final class HydrateCycleRecords implements HydrationPassInterface
{
	public function __construct(
		private readonly ?CycleRecordLoader $records,
	) {
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('HydrateCycleRecords requires a record node.');
		}

		if ($this->records === null) {
			return $subject;
		}

		$this->hydrateCurrentSnapshot($subject);
		$this->hydrateWorkingRecord($subject);

		return $subject;
	}

	private function hydrateCurrentSnapshot(RecordNode $node): void
	{
		if ($node->operation === 'create' || $node->state === null) {
			return;
		}

		if ($node->currentData !== null && $this->currentDataSatisfiesNode($node)) {
			if ($node->currentRecord === null) {
				$node->currentRecord = $this->records->hydrateRecord($node->collection, $node->currentData);
			}

			return;
		}

		$identity = $node->state->getPrimaryKeyValue(false);
		if ($identity === null) {
			return;
		}
		foreach ($identity->getValues() as $value) {
			if ($value instanceof ValueRef && ! $value->isReady()) {
				return;
			}
		}

		$snapshot = $this->records->findSnapshotByIdentity(
			$node->collection,
			$identity,
			$this->relationPathsForNode($node),
		);
		$node->currentRecord = $snapshot['record'] ?? null;
		$node->currentData = $snapshot['data'] ?? null;
	}

	private function hydrateWorkingRecord(RecordNode $node): void
	{
		if ($node->record !== null) {
			return;
		}

		if ($node->operation !== 'create' && $node->currentRecord !== null) {
			$record = clone $node->currentRecord;
			foreach ($node->fields as $field => $value) {
				if (! $node->collection->fields->has((string) $field)) {
					continue;
				}

				if ($value instanceof ValueRef) {
					if (! $value->isReady()) {
						continue;
					}

					$value = $value->resolve();
				}

				$record->{$field} = $value;
			}

			$node->record = $record;

			return;
		}

		$data = match ($node->operation) {
			'create' => $node->fields,
			'delete' => $node->currentData ?? $node->fields,
			default => ($node->currentData ?? []) === []
				? $node->fields
				: [...$node->currentData ?? [], ...$node->fields],
		};
		$data = $this->normalizeRecordData($data);

		$node->record = $node->operation === 'create'
			? $this->records->createRecord($node->collection, $data)
			: $this->records->hydrateRecord($node->collection, $data);
	}

	private function currentDataSatisfiesNode(RecordNode $node): bool
	{
		if ($node->relations === []) {
			return true;
		}

		foreach (array_keys($node->relations) as $relationName) {
			if (! array_key_exists($relationName, $node->currentData ?? [])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private function relationPathsForNode(RecordNode $node, string $prefix = ''): array
	{
		$paths = [];

		foreach ($node->relations as $relationName => $relation) {
			$path = $prefix === '' ? $relationName : $prefix . '.' . $relationName;
			$paths[$path] = true;

			foreach ($relation->children as $child) {
				if ($child->isRelationMutation()) {
					foreach ($this->relationPathsForNode($child, $path) as $nestedPath) {
						$paths[$nestedPath] = true;
					}
				}
			}
		}

		return array_keys($paths);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function normalizeRecordData(array $data): array
	{
		foreach ($data as $field => $value) {
			if (! $value instanceof ValueRef) {
				continue;
			}

			if ($value->isReady()) {
				$data[$field] = $value->resolve();
				continue;
			}

			unset($data[$field]);
		}

		return $data;
	}
}
