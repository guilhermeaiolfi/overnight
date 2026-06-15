<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\NodeStateInterface;

final class MutationContext
{
	public function __construct(
		public readonly CollectionInterface $collection,
		public readonly NodeStateInterface $source,
		public readonly string $parentOperation,
	) {
	}

	public function withCollection(CollectionInterface $collection, NodeStateInterface $source): self
	{
		return new self($collection, $source, $this->parentOperation);
	}

	public function withOperation(string $operation): self
	{
		return new self($this->collection, $this->source, $operation);
	}
}
