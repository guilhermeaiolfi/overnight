<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\SelectQuery;
use ON\RestApi\Query\QueryContext;

interface QueryParserInterface
{
	/**
	 * Build a SelectQuery for the collection from protocol query parameters.
	 *
	 * @param array<string, mixed> $parameters
	 */
	public function parse(
		CollectionInterface $collection,
		array $parameters,
		QueryContext $context,
	): SelectQuery;
}
