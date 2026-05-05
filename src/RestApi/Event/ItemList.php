<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;

class ItemList implements AuthorizationAwareEventInterface, HasEventNameInterface, PreventableEventInterface
{
	use AuthorizationAwareEventTrait;

	private bool $defaultPrevented = false;
	private ?array $result = null;
	private ?int $totalCount = null;

	public function __construct(
		protected CollectionInterface $collection,
		protected array $params
	) {
	}

	public function eventName(): string
	{
		return 'restapi.item.list';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getParams(): array
	{
		return $this->params;
	}

	public function isAggregate(): bool
	{
		return $this->getAggregate() !== null;
	}

	public function getAggregate(): ?array
	{
		$aggregate = $this->params['aggregate'] ?? null;

		return is_array($aggregate) && $aggregate !== [] ? $aggregate : null;
	}

	public function getGroupBy(): ?array
	{
		$groupBy = $this->params['groupBy'] ?? null;

		return is_array($groupBy) && $groupBy !== [] ? $groupBy : null;
	}

	public function getResult(): ?array
	{
		return $this->result;
	}

	public function getTotalCount(): ?int
	{
		return $this->totalCount;
	}

	public function setResult(array $items, ?int $totalCount = null): void
	{
		$this->result = $items;
		$this->totalCount = $totalCount;
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
