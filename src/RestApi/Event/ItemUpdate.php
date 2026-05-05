<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;

class ItemUpdate implements AuthorizationAwareEventInterface, HasEventNameInterface, PreventableEventInterface
{
	use AuthorizationAwareEventTrait;

	private bool $defaultPrevented = false;
	private ?array $result = null;

	public function __construct(
		protected CollectionInterface $collection,
		protected string $id,
		protected array $input
	) {
	}

	public function eventName(): string
	{
		return 'restapi.item.update';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getInput(): array
	{
		return $this->input;
	}

	public function setInput(array $input): void
	{
		$this->input = $input;
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
