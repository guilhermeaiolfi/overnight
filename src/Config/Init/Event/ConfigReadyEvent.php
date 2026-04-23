<?php

declare(strict_types=1);

namespace ON\Config\Init\Event;

use ON\Config\ConfigExtension;

final class ConfigReadyEvent
{
	public function __construct(
		public ConfigExtension $config
	) {
	}
}
