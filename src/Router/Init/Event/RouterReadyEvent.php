<?php

declare(strict_types=1);

namespace ON\Router\Init\Event;

use ON\Router\RouterExtension;

final class RouterReadyEvent
{
	public function __construct(
		public RouterExtension $router
	) {
	}
}
