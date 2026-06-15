<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

/**
 * Container for the hydrated record graph rooted at a single top-level record node.
 */
final class RecordStore
{
	public function __construct(
		public RecordNode $root,
	) {
	}
}
