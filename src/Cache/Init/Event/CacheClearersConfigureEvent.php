<?php

declare(strict_types=1);

namespace ON\Cache\Init\Event;

use ON\Application;
use ON\Cache\CacheClearerRegistry;

final class CacheClearersConfigureEvent
{
	public function __construct(
		public CacheClearerRegistry $registry,
		public Application $app,
	) {
	}
}
