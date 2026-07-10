<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use ON\Data\Key;

/**
 * Compact related-item representation for nested Directus payloads.
 */
final readonly class RelatedItemInput
{
	/**
	 * @param array<string, mixed> $values
	 * @param list<RelationMutation> $relations
	 */
	public function __construct(
		public ?Key $identity,
		public array $values,
		public array $relations,
		public PayloadPath $path,
		public bool $forceNew = false,
	) {
	}

	public function isNew(): bool
	{
		return $this->forceNew || $this->identity === null;
	}
}
