<?php

declare(strict_types=1);

namespace ON\Container\Init\Event;

use ON\Container\ContainerConfig;
use ON\Container\ContainerExtension;
use ON\Init\Attribute\InitEvent;

#[InitEvent(owner: ContainerExtension::class)]
final class ContainerConfigureEvent
{
	public function __construct(
		public ContainerExtension $containerExtension,
		public ContainerConfig $containerConfig
	) {
	}
}
