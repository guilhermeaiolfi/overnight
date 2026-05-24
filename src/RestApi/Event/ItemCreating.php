<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;

class ItemCreating implements AuthorizationAwareEventInterface, HasEventNameInterface
{
	use AuthorizationAwareEventTrait;

	public function __construct(
		protected MutationNode $node,
		protected MutationQueue $queue,
		protected array $path = [],
		protected ?CollectionInterface $rootCollection = null,
		protected ?MutationStateInterface $rootState = null
	) {
		$this->path = $path === [] ? $node->path : $path;
		$this->rootCollection ??= $node->collection;
		$this->rootState ??= $node->state;
	}

	public function eventName(): string
	{
		return 'restapi.item.creating';
	}

	public function getNode(): MutationNode
	{
		return $this->node;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->node->collection;
	}

	public function getState(): MutationStateInterface
	{
		return $this->node->state;
	}

	public function getQueue(): MutationQueue
	{
		return $this->queue;
	}

	public function getPath(): array
	{
		return $this->path;
	}

	public function getPathString(): string
	{
		return implode('.', array_map('strval', $this->path));
	}

	public function isRoot(): bool
	{
		return $this->path === [];
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
