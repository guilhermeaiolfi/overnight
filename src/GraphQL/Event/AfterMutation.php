<?php

declare(strict_types=1);

namespace ON\GraphQL\Event;

use ON\Event\HasEventNameInterface;
use ON\ORM\Definition\Collection\Collection;

class AfterMutation implements HasEventNameInterface
{
	public function __construct(
		protected Collection $collection,
		protected string $operation,
		protected mixed $result
	) {
	}

	public function eventName(): string
	{
		return 'graphql.mutation.after';
	}

	public function getCollection(): Collection
	{
		return $this->collection;
	}

	public function getOperation(): string
	{
		return $this->operation;
	}

	public function getResult(): mixed
	{
		return $this->result;
	}
}
