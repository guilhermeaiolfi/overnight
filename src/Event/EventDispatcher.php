<?php

declare(strict_types=1);

namespace ON\Event;

use League\Event\EventDispatcher as LeagueEventDispatcher;
use League\Event\ListenerPriority;

class EventDispatcher extends LeagueEventDispatcher
{
	public function once(string $event, callable $listener, int $priority = ListenerPriority::NORMAL): void
	{
		$this->subscribeOnceTo($event, $listener, $priority);
	}

	public function on(string $event, callable $listener, int $priority = ListenerPriority::NORMAL): void
	{
		$this->subscribeTo($event, $listener, $priority);
	}
}
