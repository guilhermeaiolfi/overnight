<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Action;

use ON\RestApi\Payload\Node\MutationNodeSpec;

final class UpdateAction implements RelationAction
{
	public function __construct(
		public ?string $collection = null,
		public ?array $data = null,
		public ?MutationNodeSpec $node = null,
		public int $index = 0,
		public bool $explicitOperation = false,
	) {
	}
}
