<?php

declare(strict_types=1);

namespace ON\Router\Init\Event;

use ON\Router\RouterExtension;

final class RouterSetupEvent
{
	public function __construct(
		public RouterExtension $router
	) {
	}
}
