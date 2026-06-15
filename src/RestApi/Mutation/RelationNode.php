<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Handler\RelationMutationHandlerInterface;

final class RelationNode
{
	/**
	 * @param list<RecordNode> $children
	 */
	public function __construct(
		public string $relationName,
		public CollectionInterface $targetCollection,
		public array $children = [],
		public ?RelationInterface $definition = null,
		public ?RelationMutationHandlerInterface $handler = null,
		public ?NodeStateInterface $state = null,
		public array $path = [],
	) {
	}

	/**
	 * @return list<RecordNode>
	 */
	public function childRecordsByOperation(?string $operation = null): array
	{
		$records = [];

		foreach ($this->children as $child) {
			if ($operation !== null && $child->operation !== $operation) {
				continue;
			}

			$records[] = $child;
		}

		return $records;
	}
}
