<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

final class LoaderFactory
{
	public function __construct(
		private LoaderRegistry $registry
	) {
	}

	public static function defaults(): self
	{
		return new self(LoaderRegistry::defaults());
	}

	public function registry(): LoaderRegistry
	{
		return $this->registry;
	}

	public function relation(RelationLoad $relation): RelationLoaderInterface
	{
		$class = $this->registry->resolve($relation->sourceCollection, $relation->responseName, $relation->relation);

		return new $class($relation, $this);
	}
}
