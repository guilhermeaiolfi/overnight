<?php

declare(strict_types=1);

namespace ON\Container\Init\Event;

use ON\Container\ContainerExtension;
use ON\Init\Attribute\InitEvent;
use Psr\Container\ContainerInterface;

#[InitEvent(owner: ContainerExtension::class)]
final class ContainerReadyEvent
{
	public function __construct(
		public ContainerExtension $containerExtension,
		public ContainerInterface $container
	) {
	}
}
