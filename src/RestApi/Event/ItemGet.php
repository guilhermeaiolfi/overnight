<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\SelectQuery;
use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\RestApi\Query\QueryContext;
use ON\RestApi\Support\PrimaryKeyValue;

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
		protected PrimaryKeyValue $identity,
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

	public function getPrimaryKeyValue(): PrimaryKeyValue
	{
		return $this->identity;
	}

	public function getIdentity(): PrimaryKeyValue
	{
		return $this->getPrimaryKeyValue();
	}

	public function getId(): PrimaryKeyValue
	{
		return $this->getPrimaryKeyValue();
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
