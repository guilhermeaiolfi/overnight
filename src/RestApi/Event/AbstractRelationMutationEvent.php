<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;

abstract class AbstractRelationMutationEvent implements HasEventNameInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected string $relationName,
		protected CollectionInterface $targetCollection,
		protected MutationStateInterface $state,
		protected mixed $target,
		protected array $path = [],
		protected ?CollectionInterface $rootCollection = null,
		protected ?MutationStateInterface $rootState = null
	) {
		$this->rootCollection ??= $collection;
		$this->rootState ??= $state;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getTargetCollection(): CollectionInterface
	{
		return $this->targetCollection;
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function getTarget(): mixed
	{
		return $this->target;
	}

	public function getPath(): array
	{
		return $this->path;
	}

	public function getPathString(): string
	{
		return implode('.', array_map('strval', $this->path));
	}

	public function getRootCollection(): CollectionInterface
	{
		return $this->rootCollection;
	}

	public function getRootState(): MutationStateInterface
	{
		return $this->rootState;
	}
}
