<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;

final class MutationContext
{
	public function __construct(
		public readonly CollectionInterface $collection,
		public readonly MutationStateInterface $source,
		public readonly string $parentOperation,
	) {
	}

	public function withCollection(CollectionInterface $collection, MutationStateInterface $source): self
	{
		return new self($collection, $source, $this->parentOperation);
	}

	public function withOperation(string $operation): self
	{
		return new self($this->collection, $this->source, $operation);
	}
}
