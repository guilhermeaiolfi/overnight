<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler\Pass;

use ON\Mapper\Representation\StorageRepresentation;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\Compiler\HydrationOptions;
use ON\RestApi\Mutation\Compiler\HydrationPassInterface;
use ON\RestApi\Mutation\Compiler\HydrationSubjectInterface;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Repository\ItemRepositoryInterface;

/**
 * Resolves whether the current node is a create or update and validates root identity constraints.
 */
final class ResolveMutationOperations implements HydrationPassInterface
{
	use MutationStateIdentity;

	public function __construct(
		private readonly ItemRepositoryInterface $items,
		private readonly ?CycleRecordLoader $records,
		private readonly HydrationOptions $options,
	) {
	}

	public function run(HydrationSubjectInterface $subject): HydrationSubjectInterface
	{
		if (! $subject instanceof RecordNode) {
			throw new \InvalidArgumentException('ResolveMutationOperations requires a record node.');
		}

		$resolvedId = $this->options->id;
		if ($resolvedId === null && $this->options->mode !== 'create') {
			$resolvedId = $subject->collection->getPrimaryKey()->extract($subject->fields);
		}
		$primaryKey = $resolvedId === null ? null : $subject->collection->getPrimaryKey()->getValue($resolvedId);
		$operation = $this->resolveOperation($this->options->mode, $subject->collection, $primaryKey);
		$subject->setOperation($operation);

		if ($operation === 'update' && $primaryKey === null) {
			throw new RestApiError(
				'Update mutation requires primary key values.',
				'MISSING_PRIMARY_KEY',
				$subject->collection->getPrimaryKey()->getFieldNames()[0] ?? null,
				400
			);
		}

		$subject->syncState();
		if ($operation === 'update' && $primaryKey !== null) {
			$this->applyPrimaryKeyValues($subject->state, $primaryKey);
		}
		$this->assertCreateIdAvailable($subject);

		return $subject;
	}

	private function resolveOperation(string $mode, CollectionInterface $collection, ?PrimaryKeyValue $id): string
	{
		if ($mode !== 'upsert') {
			return $mode;
		}

		$current = $id === null ? null : (
			$this->records?->findByIdentity($collection, $id)
			?? $this->items->findByIdentity($collection, $id, StorageRepresentation::class)
		);

		return $current !== null ? 'update' : 'create';
	}

	private function assertCreateIdAvailable(RecordNode $node): void
	{
		if ($node->operation !== 'create') {
			return;
		}

		$createId = $node->collection->getPrimaryKey()->extract($node->state->getData());
		if (
			$createId !== null
			&& !array_filter($createId->getValues(), static fn (mixed $value): bool => $value instanceof ValueRef)
			&& (
				$this->records?->findByIdentity($node->collection, $createId)
				?? $this->items->findByIdentity($node->collection, $createId, StorageRepresentation::class)
			) !== null
		) {
			throw new RestApiError(
				'A record with this ' . implode(', ', $node->collection->getPrimaryKey()->getFieldNames()) . ' already exists.',
				'DUPLICATE',
				$node->collection->getPrimaryKey()->getFieldNames()[0] ?? null,
				409
			);
		}
	}
}
