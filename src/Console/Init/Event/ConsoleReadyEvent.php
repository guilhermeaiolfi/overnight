<?php

declare(strict_types=1);

namespace ON\Console\Init\Event;

use ON\Console\ConsoleExtension;
use ON\Init\Attribute\InitEvent;

#[InitEvent(owner: ConsoleExtension::class)]
final class ConsoleReadyEvent
{
	public function __construct(
		public ConsoleExtension $console
	) {
	}
}
