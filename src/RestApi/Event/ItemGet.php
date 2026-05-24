<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Event\HasEventNameInterface;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Query\Node\QuerySpec;

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
		protected ?QuerySpec $querySpec = null,
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

	public function getIdentity(): PrimaryKeyValue
	{
		return $this->identity;
	}

	/**
	 * URL-safe route id (scalar or encoded composite primary key).
	 */
	public function getId(): string
	{
		return $this->identity->toUrlId();
	}

	public function getQuerySpec(): ?QuerySpec
	{
		return $this->querySpec;
	}

	public function setQuerySpec(?QuerySpec $querySpec): void
	{
		$this->querySpec = $querySpec;
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
