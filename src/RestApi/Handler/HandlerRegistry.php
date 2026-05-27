<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\BelongsToRelation;
use ON\ORM\Definition\Relation\FirstOfManyRelation;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Relation\RelationInterface;

class HandlerRegistry
{
	/** @var array<string, class-string<HandlerInterface>> */
	private array $relations = [];

	/** @var array<string, class-string<HandlerInterface>> */
	private array $defaults = [];

	public static function defaults(): self
	{
		return (new self())
			->default('hasOne', HasOneHandler::class)
			->default('belongsTo', BelongsToHandler::class)
			->default('firstOfMany', FirstOfManyHandler::class)
			->default('hasMany', HasManyHandler::class)
			->default('manyToMany', ManyToManyHandler::class);
	}

	/**
	 * @param class-string<HandlerInterface> $handlerClass
	 */
	public function relation(string $collectionName, string $relationName, string $handlerClass): self
	{
		$key = $this->relationKey($collectionName, $relationName);
		if (isset($this->relations[$key])) {
			throw new \LogicException("Handler already registered for relation {$key}.");
		}

		$this->relations[$key] = $handlerClass;

		return $this;
	}

	/**
	 * @param class-string<HandlerInterface> $handlerClass
	 */
	public function replaceRelation(string $collectionName, string $relationName, string $handlerClass): self
	{
		$this->relations[$this->relationKey($collectionName, $relationName)] = $handlerClass;

		return $this;
	}

	/**
	 * @param class-string<HandlerInterface> $handlerClass
	 */
	public function default(string $kind, string $handlerClass): self
	{
		if (isset($this->defaults[$kind])) {
			throw new \LogicException("Default handler already registered for {$kind}.");
		}

		$this->defaults[$kind] = $handlerClass;

		return $this;
	}

	/**
	 * @return class-string<HandlerInterface>
	 */
	public function resolve(CollectionInterface $collection, string $responseName, RelationInterface $relation): string
	{
		$key = $this->relationKey($collection->getName(), $responseName);
		if (isset($this->relations[$key])) {
			return $this->relations[$key];
		}

		$kind = $this->relationKind($relation);
		if (isset($this->defaults[$kind])) {
			return $this->defaults[$kind];
		}

		throw new \RuntimeException("No REST SQL handler configured for {$key} ({$kind}).");
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
			return 'firstOfMany';
		}

		if ($relation instanceof HasManyRelation) {
			return 'hasMany';
		}

		if ($relation instanceof HasOneRelation) {
			return 'hasOne';
		}

		return $relation->getCardinality() === 'many' ? 'hasMany' : 'hasOne';
	}
}
