<?php

declare(strict_types=1);

namespace ON\DB\DebugPDO;

use ON\Event\HasEventNameInterface;

class QueryEvent implements HasEventNameInterface
{
	public function __construct(
		private string $name,
		private object $query,
		private string $type
	) {
	}

	public function eventName(): string
	{
		return $this->name;
	}

	public function getQuery(): object
	{
		return $this->query;
	}

	public function getType(): string
	{
		return $this->type;
	}
}
