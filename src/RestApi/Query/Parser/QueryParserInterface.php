<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\QuerySpec;

interface QueryParserInterface
{
	public function parse(CollectionInterface $collection, array $input): QuerySpec;
}
