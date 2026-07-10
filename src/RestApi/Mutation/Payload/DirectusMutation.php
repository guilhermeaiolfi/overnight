<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

/**
 * Normalized Directus root mutation: scalars plus relation protocol intents.
 * Root create/update/delete is known by the endpoint, not duplicated here.
 */
final readonly class DirectusMutation
{
	/**
	 * @param array<string, mixed> $values
	 * @param list<RelationMutation> $relations
	 */
	public function __construct(
		public array $values,
		public array $relations = [],
		public PayloadPath $path = new PayloadPath([]),
	) {
	}

	public function hasRelations(): bool
	{
		return $this->relations !== [];
	}
}
