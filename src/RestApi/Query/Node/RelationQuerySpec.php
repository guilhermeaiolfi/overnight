<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Node;

final class RelationQuerySpec
{
	/**
	 * @param list<SortSpec> $sort
	 */
	public function __construct(
		public readonly SelectionSet $selection,
		public readonly ?FilterNode $filter = null,
		public readonly ?SearchField $search = null,
		public readonly array $sort = [],
		public readonly ?PaginationSpec $pagination = null,
	) {
	}
}
