<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\Database\DatabaseInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Resolver\Sql\SqlExpressionBuilder;
use ON\RestApi\Resolver\Sql\SqlFilterApplier;

final class QueryContext
{
	public function __construct(
		public readonly DatabaseInterface $database,
		public readonly Registry $registry,
		public readonly SqlFilterApplier $filterApplier,
		public readonly SqlExpressionBuilder $expressions,
		public readonly AliasRegistry $aliases
	) {
	}
}
