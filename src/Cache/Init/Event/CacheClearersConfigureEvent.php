<?php

declare(strict_types=1);

namespace ON\Cache\Init\Event;

use ON\Application;
use ON\Cache\CacheClearerRegistry;
use ON\Init\Attribute\InitEvent;

#[InitEvent(lazy: true)]
final class CacheClearersConfigureEvent
{
	public function __construct(
		public CacheClearerRegistry $registry,
		public Application $app,
	) {
	}
}
