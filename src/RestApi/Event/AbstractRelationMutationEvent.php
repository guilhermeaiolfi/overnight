<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Event\HasEventNameInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Mutation\RelationNode;

abstract class AbstractRelationMutationEvent implements HasEventNameInterface
{
	public function __construct(
		protected RelationNode $relation,
		protected mixed $target,
		protected array $path = [],
		protected ?MutationStateInterface $rootState = null,
		protected ?MutationQueue $queue = null,
	) {
		$this->path = $path === [] ? $relation->path : $path;
		$this->rootState ??= $relation->state;
	}

	public function getRelation(): RelationNode
	{
		return $this->relation;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->relation->state->getCollection();
	}

	public function getRelationName(): string
	{
		return $this->relation->handler->getRelationName();
	}

	public function getTargetCollection(): CollectionInterface
	{
		return $this->relation->handler->getTargetCollection();
	}

	public function getState(): MutationStateInterface
	{
		return $this->relation->state;
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
		return $this->rootState->getCollection();
	}

	public function getRootState(): MutationStateInterface
	{
		return $this->rootState;
	}

	public function getQueue(): MutationQueue
	{
		if ($this->queue === null) {
			throw new LogicException('Queue is only available on before relation events.');
		}

		return $this->queue;
	}
}
