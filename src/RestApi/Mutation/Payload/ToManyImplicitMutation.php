<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use ON\Data\Definition\Relation\RelationInterface;

/**
 * Basic to-many array payload: desired final relation contents (implicit full set).
 * Omitted current members are unlinked, unless the relation is exclusive (then deleted).
 */
final readonly class ToManyImplicitMutation implements RelationMutation
{
	/**
	 * @param list<RelatedItemInput> $items
	 */
	public function __construct(
		private RelationInterface $relation,
		private PayloadPath $path,
		public array $items,
	) {
	}

	public function relation(): RelationInterface
	{
		return $this->relation;
	}

	public function path(): PayloadPath
	{
		return $this->path;
	}
}
