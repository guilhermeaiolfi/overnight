<?php

declare(strict_types=1);

namespace ON\GraphQL\Event;

use ON\Event\HasEventNameInterface;

class QueryComplete implements HasEventNameInterface
{
	public function __construct(
		protected array $result
	) {
	}

	public function eventName(): string
	{
		return 'graphql.query.complete';
	}

	public function getResult(): array
	{
		return $this->result;
	}
}
