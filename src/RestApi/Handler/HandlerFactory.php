<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\RelationSelection;

class HandlerFactory
{
	public function __construct(
		private HandlerRegistry $registry
	) {
	}

	public static function defaults(): self
	{
		return new self(HandlerRegistry::defaults());
	}

	public function registry(): HandlerRegistry
	{
		return $this->registry;
	}

	public function relation(
		CollectionInterface $source,
		RelationSelection $selection,
		QueryContext $context
	): ?HandlerInterface {
		$relationName = $selection->relationName;
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);
		$class = $this->registry->resolve($source, $selection->responseName, $relation);

		return new $class($source, $relation, $selection, $context);
	}

	public function mutation(CollectionInterface $source, string $relationName): ?HandlerInterface
	{
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);
		$class = $this->registry->resolve($source, $relationName, $relation);

		return new $class($source, $relation);
	}
}
