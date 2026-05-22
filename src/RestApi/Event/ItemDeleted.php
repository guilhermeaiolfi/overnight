<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;

class ItemDeleted implements HasEventNameInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected MutationStateInterface $state,
		protected bool $result,
		protected array $path = [],
		protected ?CollectionInterface $rootCollection = null,
		protected ?MutationStateInterface $rootState = null
	) {
		$this->rootCollection ??= $collection;
		$this->rootState ??= $state;
	}

	public function eventName(): string
	{
		return 'restapi.item.deleted';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getId(): string
	{
		return (string) $this->state->resolveValue('id');
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function getRow(): ?array
	{
		return $this->state->getRow();
	}

	public function getResult(): bool
	{
		return $this->result;
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
