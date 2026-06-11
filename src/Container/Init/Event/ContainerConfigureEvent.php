<?php

declare(strict_types=1);

namespace ON\Container\Init\Event;

use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;

final class ContainerConfigureEvent
{
	public function __construct(
		public ContainerExtension $containerExtension,
		public ContainerConfig $containerConfig
	) {
	}
}
