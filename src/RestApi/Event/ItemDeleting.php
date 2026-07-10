<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Event\HasEventNameInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Support\PrimaryKeyValue;

class ItemDeleting implements AuthorizationAwareEventInterface, HasEventNameInterface
{
	use AuthorizationAwareEventTrait;

	public function __construct(
		protected MutationNode $node,
		protected PrimaryKeyValue $identity,
		protected MutationQueue $queue,
		protected array $path = [],
		protected ?MutationStateInterface $rootState = null
	) {
		$this->path = $path === [] ? $node->path : $path;
		$this->rootState ??= $node->state;
	}

	public function eventName(): string
	{
		return 'restapi.item.deleting';
	}

	public function getNode(): MutationNode
	{
		return $this->node;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->node->collection;
	}

	public function getPrimaryKeyValue(): PrimaryKeyValue
	{
		return $this->identity;
	}

	public function getId(): PrimaryKeyValue
	{
		return $this->getPrimaryKeyValue();
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
		return $this->rootState->getCollection();
	}

	public function getRootState(): MutationStateInterface
	{
		return $this->rootState;
	}
}
