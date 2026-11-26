<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\ValueRef;
use ON\RestApi\Mutation\Payload\DirectusMutation;
use ON\RestApi\Mutation\Payload\PayloadPath;

/**
 * Session-bound mutation ready for hooks, sync, and flush.
 */
final class BoundMutation
{
	/**
	 * @param list<BoundMutation> $related
	 * @param array<string, mixed> $submittedValues Nested scalar input before type conversion
	 * @param array<string, ValueRef> $parentKeys Child fields supplied by the parent relation
	 */
	public function __construct(
		public readonly string $operation,
		public readonly CollectionInterface $collection,
		public readonly object $representation,
		public readonly BoundItemState $state,
		public readonly DirectusMutation $mutation,
		public readonly ?Key $identity = null,
		public readonly PayloadPath $path = new PayloadPath([]),
		public array $related = [],
		public readonly ?RecordState $rootRecord = null,
		public readonly array $submittedValues = [],
		public readonly array $parentKeys = [],
	) {
	}

	public function isRoot(): bool
	{
		return $this->path->isRoot();
	}
}
