<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;

final class MutationNode
{
	/** @param array<string, RelationNode> $relations */
	public function __construct(
		public string $operation,
		public CollectionInterface $collection,
		public MutationStateInterface $state,
		public array $path,
		public array $relations = [],
	) {
	}

	public function setOperation(string $operation): void
	{
		if (! in_array($operation, ['create', 'update', 'delete'], true)) {
			throw new InvalidArgumentException(
				sprintf('Mutation operation must be create, update or delete. Got "%s".', $operation)
			);
		}

		$this->operation = $operation;
	}
}
