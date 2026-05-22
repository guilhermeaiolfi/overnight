<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;

class ItemCreated implements HasEventNameInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected MutationStateInterface $state,
		protected array $path = [],
		protected ?CollectionInterface $rootCollection = null,
		protected ?MutationStateInterface $rootState = null
	) {
		$this->rootCollection ??= $collection;
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

	public function getState(): MutationStateInterface
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
		return $this->rootCollection;
	}

	public function getRootState(): MutationStateInterface
	{
		return $this->rootState;
	}
}
