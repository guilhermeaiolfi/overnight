<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\SelectQuery;
use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\RestApi\Query\QueryContext;

class ItemList implements AuthorizationAwareEventInterface, HasEventNameInterface, PreventableEventInterface
{
	use AuthorizationAwareEventTrait;

	private bool $defaultPrevented = false;
	private ?array $result = null;
	private ?int $totalCount = null;

	/**
	 * @param array<string, mixed> $options
	 */
	public function __construct(
		protected CollectionInterface $collection,
		protected SelectQuery $query,
		protected QueryContext $context,
		protected array $options = [],
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

	/**
	 * @return array<string, mixed>
	 */
	public function getOptions(): array
	{
		return $this->options;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function setOptions(array $options): void
	{
		$this->options = $options;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function setQuery(SelectQuery $query): void
	{
		$this->query = $query;
	}

	public function getContext(): QueryContext
	{
		return $this->context;
	}

	public function setContext(QueryContext $context): void
	{
		$this->context = $context;
	}

	public function isAggregate(): bool
	{
		return $this->context->isAggregate();
	}

	/**
	 * @return list<array{function: string, field: string, alias: string}>|null
	 */
	public function getAggregate(): ?array
	{
		return $this->context->getAggregates() !== [] ? $this->context->getAggregates() : null;
	}

	/**
	 * @return list<array{responseName: string, alias: string}>|null
	 */
	public function getGroupBy(): ?array
	{
		return $this->context->getGroupBy() !== [] ? $this->context->getGroupBy() : null;
	}

	/**
	 * @return list<string>
	 */
	public function getMeta(): array
	{
		return $this->context->getMeta();
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
