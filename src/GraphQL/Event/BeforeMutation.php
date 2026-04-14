<?php

declare(strict_types=1);

namespace ON\GraphQL\Event;

use League\Event\HasEventName;
use ON\ORM\Definition\Collection\Collection;

class BeforeMutation implements HasEventName
{
	public function __construct(
		protected Collection $collection,
		protected string $operation,
		protected array $input
	) {
	}

	public function eventName(): string
	{
		return 'graphql.mutation.before';
	}

	public function getCollection(): Collection
	{
		return $this->collection;
	}

	public function getOperation(): string
	{
		return $this->operation;
	}

	public function getInput(): array
	{
		return $this->input;
	}

	/**
	 * Replace the input data. Listeners can modify input before the resolver runs.
	 */
	public function setInput(array $input): void
	{
		$this->input = $input;
	}
}
