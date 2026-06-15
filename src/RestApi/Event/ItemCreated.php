<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\NodeStateInterface;

class ItemCreated implements HasEventNameInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected NodeStateInterface $state,
		protected array $path = [],
		protected ?NodeStateInterface $rootState = null
	) {
		$this->rootState ??= $state;
	}

	public function eventName(): string
	{
		return 'restapi.item.created';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getState(): NodeStateInterface
	{
		return $this->state;
	}

	public function getRow(): ?array
	{
		return $this->state->getRow();
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
