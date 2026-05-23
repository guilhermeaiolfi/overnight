<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Relation\RelationInterface;
use ON\RestApi\Payload\Expander\BelongsToRelationPayloadExpander;
use ON\RestApi\Payload\Expander\HasManyRelationPayloadExpander;
use ON\RestApi\Payload\Expander\HasOneRelationPayloadExpander;
use ON\RestApi\Payload\Expander\ManyToManyRelationPayloadExpander;
use ON\RestApi\Payload\Expander\RelationPayloadExpanderInterface;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;

class HandlerFactory
{
	public function __construct(
		private HandlerRegistry $registry,
		private SqlDataSource $dataSource,
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

	public function payloadExpander(CollectionInterface $source, string $relationName): ?RelationPayloadExpanderInterface
	{
		if (!$source->relations->has($relationName)) {
			return null;
		}

		$relation = $source->relations->get($relationName);
		$class = $this->registry->resolve($source, $relationName, $relation);

		return match ($class) {
			HasManyHandler::class => new HasManyRelationPayloadExpander($source, $relation, $this->dataSource),
			HasOneHandler::class => new HasOneRelationPayloadExpander($source, $relation, $this->dataSource),
			BelongsToHandler::class => new BelongsToRelationPayloadExpander($source, $relation, $this->dataSource),
			ManyToManyHandler::class => new ManyToManyRelationPayloadExpander(
				$source,
				$relation instanceof M2MRelation ? $relation : throw new \LogicException('Expected M2M relation.'),
				$this->dataSource,
			),
			default => null,
		};
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
			$this->dataSource,
			$this->querySpecCompiler,
			$selection,
			$aliases
		);
	}
}
