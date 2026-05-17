<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class PaginationSpec
{
	public function __construct(
		public readonly int $limit,
		public readonly int $offset = 0,
	) {
	}
}
