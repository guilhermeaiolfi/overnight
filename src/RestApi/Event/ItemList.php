<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Query\Node\QuerySpec;

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

	public function setParams(array $params): void
	{
		$this->params = $params;
	}

	public function getQuerySpec(): ?QuerySpec
	{
		return ($this->params['querySpec'] ?? null) instanceof QuerySpec ? $this->params['querySpec'] : null;
	}

	public function setQuerySpec(QuerySpec $querySpec): void
	{
		$this->params['querySpec'] = $querySpec;
	}

	public function isAggregate(): bool
	{
		return $this->getAggregate() !== null;
	}

	public function getAggregate(): ?array
	{
		return $this->getQuerySpec()?->aggregate ?: null;
	}

	public function getGroupBy(): ?array
	{
		return $this->getQuerySpec()?->groupBy ?: null;
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
