<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

/**
 * Result of a flushed Directus mutation after reload.
 */
final readonly class MutationResult
{
	/**
	 * @param array<string, mixed>|null $data
	 */
	public function __construct(
		public ?array $data,
		public bool $deleted = false,
	) {
	}
}
