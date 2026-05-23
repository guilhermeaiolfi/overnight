<?php

declare(strict_types=1);

namespace ON\RestApi\Payload\Node;

final class MutationSpec
{
	public function __construct(
		public readonly MutationNodeSpec $root,
	) {
	}
}
