<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;

final readonly class LinkIntent
{
	public function __construct(
		public CollectionInterface $collection,
		public PrimaryKeyValue|int|string $target,
	) {
	}
}
