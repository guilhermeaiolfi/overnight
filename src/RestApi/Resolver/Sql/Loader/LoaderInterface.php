<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;

interface LoaderInterface
{
	public function prepare(QueryContext $query): void;

	public function load(AbstractNode $node): void;
}
