<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use ON\ORM\Definition\Collection\CollectionInterface;

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

	public function relation(
		CollectionInterface $source,
		string $responseName,
		array $relationData,
		array $deep,
		QueryContext $context
	): ?RelationLoaderInterface
	{
		$targetRelationName = $relationData['_relation'] ?? $responseName;
		if (!$source->relations->has($targetRelationName)) {
			return null;
		}

		$relation = $source->relations->get($targetRelationName);
		if ($context->registry->getCollection($relation->getCollection()) === null) {
			return null;
		}

		$class = $this->registry->resolve($source, $responseName, $relation);

		return new $class($relation, $responseName, $relationData, $deep, $context);
	}
}
