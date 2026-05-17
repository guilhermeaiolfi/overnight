<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class QuerySpec
{
	/**
	 * @param list<SortSpec> $sort
	 * @param list<AggregateSpec> $aggregate
	 * @param list<GroupBySpec> $groupBy
	 * @param list<string> $meta
	 */
	public function __construct(
		public readonly string $collection,
		public readonly SelectionSet $selection,
		public readonly ?FilterNode $filter = null,
		public readonly ?SearchField $search = null,
		public readonly array $sort = [],
		public readonly ?PaginationSpec $pagination = null,
		public readonly array $aggregate = [],
		public readonly array $groupBy = [],
		public readonly array $meta = [],
	) {
	}
}
