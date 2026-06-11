<?php

declare(strict_types=1);

namespace ON\Cache\Init\Event;

use ON\Application;
use ON\Cache\CacheClearerRegistry;
use ON\Init\NonLifecycleOrderingEventInterface;

final class CacheClearersConfigureEvent implements NonLifecycleOrderingEventInterface
{
	public function __construct(
		public CacheClearerRegistry $registry,
		public Application $app,
	) {
	}
}
