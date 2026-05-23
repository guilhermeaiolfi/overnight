<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Node;

final class MutationNodeSpec
{
	/**
	 * @param array<string, mixed> $fields
	 * @param list<RelationPayload> $relations
	 */
	public function __construct(
		public string $collection,
		public array $fields = [],
		public array $relations = [],
		public string $operation = 'pending',
	) {
	}
}
