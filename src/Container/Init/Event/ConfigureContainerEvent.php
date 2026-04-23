<?php

declare(strict_types=1);

namespace ON\Container\Init\Event;

use ON\Config\ConfigExtension;
use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;

final class ConfigureContainerEvent
{
	public function __construct(
		public ContainerExtension $containerExtension,
		public ConfigExtension $config,
		public ContainerConfig $container
	) {
	}
}
