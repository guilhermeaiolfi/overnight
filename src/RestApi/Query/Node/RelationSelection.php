<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class RelationSelection implements SelectionNode
{
	public function __construct(
		public readonly string $responseName,
		public readonly string $relationName,
		public readonly string $targetCollection,
		public readonly RelationQuerySpec $query,
		public readonly ?RelationLoadHint $loadHint = null,
	) {
	}

	public function responseName(): string
	{
		return $this->responseName;
	}
}
