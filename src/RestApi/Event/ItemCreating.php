<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\OperationQueue;
use ON\RestApi\Mutation\NodeStateInterface;

class ItemCreating implements AuthorizationAwareEventInterface, HasEventNameInterface
{
	use AuthorizationAwareEventTrait;

	public function __construct(
		protected RecordNode $node,
		protected OperationQueue $queue,
		protected array $path = [],
		protected ?NodeStateInterface $rootState = null
	) {
		$this->path = $path === [] ? $node->path : $path;
		$this->rootState ??= $node->state;
	}

	public function eventName(): string
	{
		return 'restapi.item.creating';
	}

	public function getNode(): RecordNode
	{
		return $this->node;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->node->collection;
	}

	public function getState(): NodeStateInterface
	{
		return $this->node->state;
	}

	public function getQueue(): OperationQueue
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
		return $this->rootState->getCollection();
	}

	public function getRootState(): NodeStateInterface
	{
		return $this->rootState;
	}
}
