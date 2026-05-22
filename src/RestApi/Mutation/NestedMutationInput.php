<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;

final class NestedMutationInput
{
	public function __construct(
		private CollectionInterface $collection,
		private array $data
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getData(): array
	{
		return $this->data;
	}
}
