<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;

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

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string> $columns
	 * @param array<int, string> $requestedColumns
	 * @param array<int, string> $internalColumns
	 */
	public function root(
		CollectionInterface $collection,
		array $rows = [],
		array $columns = [],
		array $requestedColumns = [],
		array $internalColumns = []
	): RootHandler {
		return new RootHandler($collection, $rows, $columns, $requestedColumns, $internalColumns);
	}

	public function relation(
		CollectionInterface $source,
		RelationSelection $selection,
		SqlDataSource $dataSource,
		SqlQuerySpecCompiler $querySpecCompiler,
		AliasRegistry $aliases
	): ?HandlerInterface {
		$relationName = $selection->relationName;
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);
		$class = $this->registry->resolve($source, $selection->responseName, $relation);

		return new $class($source, $relation, $selection, $dataSource, $querySpecCompiler, $aliases);
	}

	public function mutation(CollectionInterface $source, string $relationName): ?MutationHandlerInterface
	{
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);
		$class = $this->registry->resolve($source, $relationName, $relation);

		return new $class($source, $relation);
	}
}
