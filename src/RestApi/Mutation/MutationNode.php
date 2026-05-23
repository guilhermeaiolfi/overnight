<?php



declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\CollectionInterface;

final readonly class MutationNode
{

	/**

	 * @param array<string, RelationNode> $relations

	 */

	public function __construct(
		public string $operation,
		public CollectionInterface $collection,
		public MutationStateInterface $state,
		public array $path,
		public array $relations = [],
	) {

	}

}
