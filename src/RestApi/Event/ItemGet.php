<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\Query\SelectQuery;
use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\RestApi\Query\QueryContext;

class ItemGet implements AuthorizationAwareEventInterface, HasEventNameInterface, PreventableEventInterface
{
	use AuthorizationAwareEventTrait;

	private bool $defaultPrevented = false;
	private ?array $result = null;

	/**
	 * @param array<string, mixed> $options
	 */
	public function __construct(
		protected CollectionInterface $collection,
		protected Key $identity,
		protected SelectQuery $query,
		protected QueryContext $context,
		protected array $options = [],
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

	public function getKey(): Key
	{
		return $this->identity;
	}

	/** @deprecated Use getKey() */
	public function getPrimaryKeyValue(): Key
	{
		return $this->getKey();
	}

	public function getIdentity(): Key
	{
		return $this->getKey();
	}

	public function getId(): Key
	{
		return $this->getKey();
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
