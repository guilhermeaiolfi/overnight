<?php

declare(strict_types=1);

namespace ON\Auth\Event;

use ON\Event\HasEventNameInterface;

class LogoutEvent implements HasEventNameInterface
{
	public function __construct(
		protected mixed $identity = null
	) {
	}

	public function eventName(): string
	{
		return 'auth.logout';
	}

	public function getIdentity(): mixed
	{
		return $this->identity;
	}
}
