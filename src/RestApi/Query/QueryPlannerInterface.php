<?php

declare(strict_types=1);

namespace ON\RestApi\Query;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Query\Node\QuerySpec;

interface QueryPlannerInterface
{
	public function list(CollectionInterface $collection, QuerySpec $querySpec, bool $typed = true): array;

	public function get(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		?QuerySpec $querySpec = null,
		bool $typed = true,
	): ?array;

	public function aggregate(CollectionInterface $collection, QuerySpec $querySpec): array;
}
