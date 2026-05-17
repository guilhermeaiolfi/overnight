<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class SearchField
{
	/**
	 * @param list<string> $fields
	 */
	public function __construct(
		public readonly string $term,
		public readonly array $fields = [],
	) {
	}
}
