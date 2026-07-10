<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Event\HasEventNameInterface;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Support\PrimaryKeyValue;

class ItemDeleting implements AuthorizationAwareEventInterface, HasEventNameInterface
{
	use AuthorizationAwareEventTrait;

	public function __construct(
		protected CollectionInterface $collection,
		protected MutationStateInterface $state,
		protected PrimaryKeyValue $identity,
		protected array $path = [],
		protected ?MutationStateInterface $rootState = null,
	) {
		$this->rootState ??= $state;
	}

	public function eventName(): string
	{
		return 'restapi.item.deleting';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getPrimaryKeyValue(): PrimaryKeyValue
	{
		return $this->identity;
	}

	public function getId(): PrimaryKeyValue
	{
		return $this->getPrimaryKeyValue();
	}

	public function getState(): MutationStateInterface
	{
		return $this->state;
	}

	public function getPath(): array
	{
		return $this->path;
	}

	public function getPathString(): string
	{
		return implode('.', array_map('strval', $this->path));
	}

	public function isRoot(): bool
	{
		return $this->path === [];
	}

	public function getRootCollection(): CollectionInterface
	{
		return $this->rootState->getCollection();
	}

	public function getRootState(): MutationStateInterface
	{
		return $this->rootState;
	}
}
