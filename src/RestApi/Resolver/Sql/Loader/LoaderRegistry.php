<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\BelongsToRelation;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\M2MRelation;
use ON\ORM\Definition\Relation\RelationInterface;

final class LoaderRegistry
{
	/** @var array<string, class-string<RelationLoaderInterface>> */
	private array $relations = [];

	/** @var array<string, class-string<RelationLoaderInterface>> */
	private array $defaults = [];

	public static function defaults(): self
	{
		return (new self())
			->default('hasOne', HasOneLoader::class)
			->default('belongsTo', BelongsToLoader::class)
			->default('hasMany', HasManyLoader::class)
			->default('manyToMany', ManyToManyLoader::class);
	}

	/**
	 * @param class-string<RelationLoaderInterface> $loaderClass
	 */
	public function relation(string $collectionName, string $relationName, string $loaderClass): self
	{
		$key = $this->relationKey($collectionName, $relationName);
		if (isset($this->relations[$key])) {
			throw new \LogicException("Loader already registered for relation {$key}.");
		}

		$this->relations[$key] = $loaderClass;

		return $this;
	}

	/**
	 * @param class-string<RelationLoaderInterface> $loaderClass
	 */
	public function replaceRelation(string $collectionName, string $relationName, string $loaderClass): self
	{
		$this->relations[$this->relationKey($collectionName, $relationName)] = $loaderClass;

		return $this;
	}

	/**
	 * @param class-string<RelationLoaderInterface> $loaderClass
	 */
	public function default(string $kind, string $loaderClass): self
	{
		if (isset($this->defaults[$kind])) {
			throw new \LogicException("Default loader already registered for {$kind}.");
		}

		$this->defaults[$kind] = $loaderClass;

		return $this;
	}

	/**
	 * @return class-string<RelationLoaderInterface>
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

		throw new \RuntimeException("No REST SQL loader configured for {$key} ({$kind}).");
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

		if ($relation instanceof HasManyRelation) {
			return 'hasMany';
		}

		if ($relation instanceof HasOneRelation) {
			return 'hasOne';
		}

		return $relation->getCardinality() === 'many' ? 'hasMany' : 'hasOne';
	}
}
