<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class RelationExistsFilter implements FilterNode
{
	public function __construct(
		public readonly string $responseName,
		public readonly string $relationName,
		public readonly string $targetCollection,
		public readonly FilterNode $filter,
	) {
	}
}
