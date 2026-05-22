<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\RelationSelection;

class LoaderFactory extends HandlerFactory
{
	public function __construct(
		LoaderRegistry $registry
	) {
		parent::__construct($registry);
	}

	public static function defaults(): self
	{
		return new self(LoaderRegistry::defaults());
	}

	public function relation(
		CollectionInterface $source,
		RelationSelection $selection,
		QueryContext $context
	): ?RelationLoaderInterface {
		$handler = parent::relation($source, $selection, $context);

		return $handler instanceof RelationLoaderInterface ? $handler : null;
	}

	public function mutation(CollectionInterface $source, string $relationName): ?RelationLoaderInterface
	{
		$handler = parent::mutation($source, $relationName);

		return $handler instanceof RelationLoaderInterface ? $handler : null;
	}
}
