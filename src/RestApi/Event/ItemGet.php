<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;

class ItemGet implements AuthorizationAwareEventInterface, HasEventNameInterface, PreventableEventInterface
{
	use AuthorizationAwareEventTrait;

	private bool $defaultPrevented = false;
	private ?array $result = null;

	public function __construct(
		protected CollectionInterface $collection,
		protected string $id,
		protected array $params = []
	) {
	}

	public function eventName(): string
	{
		return 'restapi.item.get';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getParams(): array
	{
		return $this->params;
	}

	public function getResult(): ?array
	{
		return $this->result;
	}

	public function setResult(?array $item): void
	{
		$this->result = $item;
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
