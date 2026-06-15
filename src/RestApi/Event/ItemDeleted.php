<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\NodeStateInterface;

class ItemDeleted implements HasEventNameInterface
{
	public function __construct(
		protected CollectionInterface $collection,
		protected NodeStateInterface $state,
		protected bool $result,
		protected array $path = [],
		protected ?NodeStateInterface $rootState = null
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

	public function getState(): NodeStateInterface
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

	public function getRootState(): NodeStateInterface
	{
		return $this->rootState;
	}
}
