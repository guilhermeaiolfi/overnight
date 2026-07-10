<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;

class HandlerRegistry
{
	/** @var array<string, class-string<RelationMutationHandlerInterface>> */
	private array $relations = [];

	/** @var array<string, class-string<RelationMutationHandlerInterface>> */
	private array $defaults = [];

	public static function defaults(): self
	{
		return (new self())
			->default('hasOne', HasOneHandler::class)
			->default('belongsTo', BelongsToHandler::class)
			->default('hasMany', HasManyHandler::class)
			->default('manyToMany', ManyToManyHandler::class);
	}

	/**
	 * @param class-string<RelationMutationHandlerInterface> $handlerClass
	 */
	public function relation(string $collectionName, string $relationName, string $handlerClass): self
	{
		$key = $this->relationKey($collectionName, $relationName);
		if (isset($this->relations[$key])) {
			throw new LogicException("Handler already registered for relation {$key}.");
		}

		$this->relations[$key] = $handlerClass;

		return $this;
	}

	/**
	 * @param class-string<RelationMutationHandlerInterface> $handlerClass
	 */
	public function replaceRelation(string $collectionName, string $relationName, string $handlerClass): self
	{
		$this->relations[$this->relationKey($collectionName, $relationName)] = $handlerClass;

		return $this;
	}

	/**
	 * @param class-string<RelationMutationHandlerInterface> $handlerClass
	 */
	public function default(string $kind, string $handlerClass): self
	{
		if (isset($this->defaults[$kind])) {
			throw new LogicException("Default handler already registered for {$kind}.");
		}

		$this->defaults[$kind] = $handlerClass;

		return $this;
	}

	/**
	 * @return class-string<RelationMutationHandlerInterface>|null
	 */
	public function resolve(CollectionInterface $collection, string $responseName, RelationInterface $relation): ?string
	{
		$key = $this->relationKey($collection->getName(), $responseName);
		if (isset($this->relations[$key])) {
			return $this->relations[$key];
		}

		$kind = $this->relationKind($relation);
		if (isset($this->defaults[$kind])) {
			return $this->defaults[$kind];
		}

		return null;
	}

	private function relationKey(string $collectionName, string $relationName): string
	{
		return $collectionName . '.' . $relationName;
	}

	private function relationKind(RelationInterface $relation): string
	{
		if ($relation instanceof M2MRelation || $relation->isJunction()) {
			return 'manyToMany';
		}

		if ($relation instanceof BelongsToRelation) {
			return 'belongsTo';
		}

		if ($relation instanceof FirstOfManyRelation) {
			return 'readOnly';
		}

		if ($relation instanceof HasManyRelation) {
			return 'hasMany';
		}

		if ($relation instanceof HasOneRelation) {
			return 'hasOne';
		}

		return $relation->getCardinality()->isMany() ? 'hasMany' : 'hasOne';
	}
}
