<?php

declare(strict_types=1);

namespace ON\Console\Init\Event;

use ON\Console\ConsoleExtension;

final class ConsoleReadyEvent
{
	public function __construct(
		public ConsoleExtension $console
	) {
	}
}
