<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;

class ItemDelete implements HasEventNameInterface, PreventableEventInterface
{
	private bool $defaultPrevented = false;
	private ?array $result = null;

	public function __construct(
		protected CollectionInterface $collection,
		protected string $id
	) {
	}

	public function eventName(): string
	{
		return 'restapi.item.delete';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getResult(): ?array
	{
		return $this->result;
	}

	public function setResult(array $result): void
	{
		$this->result = $result;
	}

	public function preventDefault(): void
	{
		$this->defaultPrevented = true;
	}

	public function isDefaultPrevented(): bool
	{
		return $this->defaultPrevented;
	}
}
