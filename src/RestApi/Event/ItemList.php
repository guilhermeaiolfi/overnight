<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\RestApi\Query\Node\QuerySpec;

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
		protected QuerySpec $querySpec,
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

	public function getQuerySpec(): QuerySpec
	{
		return $this->querySpec;
	}

	public function setQuerySpec(QuerySpec $querySpec): void
	{
		$this->querySpec = $querySpec;
	}

	public function isAggregate(): bool
	{
		return $this->getAggregate() !== null;
	}

	public function getAggregate(): ?array
	{
		return $this->querySpec->aggregate ?: null;
	}

	public function getGroupBy(): ?array
	{
		return $this->querySpec->groupBy ?: null;
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
