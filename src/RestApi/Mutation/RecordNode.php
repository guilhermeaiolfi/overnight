<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use Cycle\ORM\Heap\Node as CycleNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;

final class RecordNode implements HydrationSubjectInterface
{
	public NodeStateInterface $state;

	/** @param array<string, RelationNode> $relations */
	public function __construct(
		public CollectionInterface $collection,
		public array $fields = [],
		public string $operation = 'pending',
		?NodeStateInterface $state = null,
		public ?array $currentData = null,
		public ?object $currentRecord = null,
		public ?object $record = null,
		public array $path = [],
		public array $relations = [],
		public ?string $relationIntent = null,
		public int $relationIndex = 0,
		public mixed $relationData = null,
		public ?PrimaryKeyValue $inputIdentity = null,
		public ?PrimaryKeyValue $currentIdentity = null,
		public ?string $plannedOperation = null,
		public bool $relationMutation = false,
	) {
		$this->state = $state ?? new NodeState($this->collection, $this->fields, $this->currentData);
	}

	public function setOperation(string $operation): void
	{
		if (! in_array($operation, ['create', 'update', 'delete'], true)) {
			throw new \InvalidArgumentException(
				sprintf('Mutation operation must be create, update or delete. Got "%s".', $operation)
			);
		}

		$this->operation = $operation;
	}

	public function isRelationMutation(): bool
	{
		return $this->relationMutation;
	}

	public function syncState(): void
	{
		$this->state->sync($this->fields, $this->currentData);
	}

	public function plan(string $plannedOperation): void
	{
		$this->plannedOperation = $plannedOperation;
		$this->relationMutation = true;

		if ($plannedOperation !== 'upsert' && $plannedOperation !== 'disconnect') {
			$this->setOperation($plannedOperation);
		}
	}

	public function mergeFields(array $fields): void
	{
		$this->fields = [...$this->fields, ...$fields];
		$this->syncState();
	}

	public function retarget(
		CollectionInterface $collection,
		array $fields,
		string $plannedOperation,
		?array $currentData = null,
	): void {
		$this->collection = $collection;
		$this->fields = [...$this->fields, ...$fields];
		$this->currentData ??= $currentData;
		$this->state = new NodeState($collection, $this->fields, $this->currentData, $this->state->isReady());
		$this->syncState();
		$this->plan($plannedOperation);
	}

	public function hasScalarChanges(): bool
	{
		if ($this->operation !== 'update') {
			return true;
		}

		foreach (array_keys($this->fields) as $fieldName) {
			$fieldName = (string) $fieldName;
			if (! $this->collection->fields->has($fieldName)) {
				continue;
			}

			$current = $this->currentScalarValue($fieldName);
			$desired = $this->desiredScalarValue($fieldName);
			if (CycleNode::compare($current, $desired) !== 0) {
				return true;
			}
		}

		return false;
	}

	private function currentScalarValue(string $fieldName): mixed
	{
		if ($this->currentRecord !== null && property_exists($this->currentRecord, $fieldName)) {
			return $this->currentRecord->{$fieldName};
		}

		return $this->currentData[$fieldName] ?? null;
	}

	private function desiredScalarValue(string $fieldName): mixed
	{
		if ($this->record !== null && property_exists($this->record, $fieldName)) {
			return $this->record->{$fieldName};
		}

		return $this->fields[$fieldName] ?? null;
	}
}
