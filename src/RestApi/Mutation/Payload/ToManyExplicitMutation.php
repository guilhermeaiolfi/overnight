<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;

/**
 * Detailed to-many object payload: incremental create/update/delete deltas.
 * Unmentioned members are left untouched. delete removes the represented item.
 * unlink is an Overnight extension for relation removal without deleting the row.
 */
final readonly class ToManyExplicitMutation implements RelationMutation
{
	/**
	 * @param list<RelatedItemInput> $create
	 * @param list<RelatedItemInput> $update
	 * @param list<Key> $delete
	 * @param list<Key> $unlink
	 */
	public function __construct(
		private RelationInterface $relation,
		private PayloadPath $path,
		public array $create = [],
		public array $update = [],
		public array $delete = [],
		public array $unlink = [],
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
