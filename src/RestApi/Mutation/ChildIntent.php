<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;

final readonly class ChildIntent
{
	public function __construct(
		public CollectionInterface $collection,
		public array $data,
	) {
	}
}
