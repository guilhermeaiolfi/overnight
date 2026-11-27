<?php

declare(strict_types=1);

namespace ON\Router\Init\Event;

use ON\Init\Attribute\InitEvent;
use ON\Router\RouterExtension;

#[InitEvent(owner: RouterExtension::class)]
final class RouterReadyEvent
{
	public function __construct(
		public RouterExtension $router
	) {
	}
}
