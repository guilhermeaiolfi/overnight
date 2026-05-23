<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;

class ItemDeleting implements AuthorizationAwareEventInterface, HasEventNameInterface, PreventableEventInterface
{
	use AuthorizationAwareEventTrait;

	private bool $defaultPrevented = false;
	private bool $preventedResult = true;

	public function __construct(
		protected CollectionInterface $collection,
		protected string $id,
		protected MutationStateInterface $state,
		protected MutationQueue $queue,
		protected array $path = [],
		protected ?CollectionInterface $rootCollection = null,
		protected ?MutationStateInterface $rootState = null
	) {
		$this->rootCollection ??= $collection;
		$this->rootState ??= $state;
	}

	public function eventName(): string
	{
		return 'restapi.item.deleting';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
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

	public function preventDefault(bool $result = true): void
	{
		$this->defaultPrevented = true;
		$this->preventedResult = $result;
	}

	public function isDefaultPrevented(): bool
	{
		return $this->defaultPrevented;
	}

	public function getPreventedResult(): bool
	{
		return $this->preventedResult;
	}
}
