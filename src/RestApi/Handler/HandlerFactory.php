<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;

final class HandlerFactory
{
	public function __construct(
		private HandlerRegistry $registry,
		private ItemRepositoryInterface $items,
	) {
	}

	public function registry(): HandlerRegistry
	{
		return $this->registry;
	}

	public function mutation(CollectionInterface $source, string $relationName): ?RelationMutationHandlerInterface
	{
		if (! $source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);
		$class = $this->registry->resolve($source, $relationName, $relation);

		if ($class === null) {
			return null;
		}

		return new $class($source, $relation, $this->items);
	}
}
