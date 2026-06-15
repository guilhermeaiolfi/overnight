<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;

class HandlerFactory
{
	public function __construct(
		private HandlerRegistry $registry,
		private ItemRepositoryInterface $items,
		private ?CycleRecordLoader $records,
		private SqlQuerySpecCompiler $querySpecCompiler,
	) {
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

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string> $columns
	 * @param array<int, string> $requestedColumns
	 * @param array<int, string> $internalColumns
	 * @param array<int, mixed> $relations
	 */
	public function configuredRoot(
		CollectionInterface $collection,
		array $rows,
		array $columns,
		array $requestedColumns,
		array $internalColumns,
		array $relations,
		AliasRegistry $aliases
	): RootHandler {
		$root = $this->root($collection, $rows, $columns, $requestedColumns, $internalColumns);
		$this->configureRelations($root, $collection, $relations, $aliases);

		return $root;
	}

	public function relation(
		CollectionInterface $source,
		RelationSelection $selection,
		AliasRegistry $aliases
	): ?HandlerInterface {
		$relationName = $selection->relationName;
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);

		return $this->createRelationHandler(
			$source,
			$relation,
			$this->registry->resolve($source, $selection->responseName, $relation),
			$selection,
			$aliases
		);
	}

	public function mutation(CollectionInterface $source, string $relationName): ?RelationMutationHandlerInterface
	{
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);

		$handler = $this->createRelationHandler(
			$source,
			$relation,
			$this->registry->resolve($source, $relationName, $relation)
		);

		return $handler instanceof RelationMutationHandlerInterface ? $handler : null;
	}

	/**
	 * @param class-string<HandlerInterface> $class
	 */
	private function createRelationHandler(
		CollectionInterface $source,
		RelationInterface $relation,
		string $class,
		?RelationSelection $selection = null,
		?AliasRegistry $aliases = null
	): HandlerInterface {
		return new $class(
			$source,
			$relation,
			$this->items,
			$this->records,
			$this->querySpecCompiler,
			$selection,
			$aliases
		);
	}

	/**
	 * @param array<int, mixed> $relations
	 */
	private function configureRelations(
		HandlerInterface $parent,
		CollectionInterface $collection,
		array $relations,
		AliasRegistry $aliases
	): void {
		$parentNode = $parent instanceof RootHandler ? $parent->rootNode() : $parent->getNode();
		foreach ($relations as $selection) {
			if (!$selection instanceof RelationSelection) {
				continue;
			}

			$handler = $this->relation($collection, $selection, $aliases);
			if ($handler === null) {
				continue;
			}

			$parent->addChild($handler);
			$handler->configureParserNode($parentNode);
			$handler->prepare();
			$this->configureRelations($handler, $handler->getTargetCollection(), $handler->getNestedRelations(), $aliases);
		}
	}
}
