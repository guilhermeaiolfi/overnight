<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Event\HasEventNameInterface;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Support\PrimaryKeyValue;

class ItemDeleted implements HasEventNameInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected MutationStateInterface $state,
		protected bool $result,
		protected array $path = [],
		protected ?MutationStateInterface $rootState = null
	) {
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

	public function getPrimaryKeyValue(): ?PrimaryKeyValue
	{
		return $this->state->getPrimaryKeyValue();
	}

	public function getId(): ?PrimaryKeyValue
	{
		return $this->getPrimaryKeyValue();
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
		return $this->rootState->getCollection();
	}

	public function getRootState(): MutationStateInterface
	{
		return $this->rootState;
	}
}
