<?php

declare(strict_types=1);

namespace ON\Event;

class NamedEvent implements HasEventNameInterface
{
	public function __construct(
		private string $name,
		private ?object $subject = null
	) {
	}

	public function eventName(): string
	{
		return $this->name;
	}

	public function getSubject(): object
	{
		return $this->subject;
	}
}
