<?php

declare(strict_types=1);

namespace ON\Container\Init\Event;

use ON\Container\ContainerExtension;
use Psr\Container\ContainerInterface;

final class ContainerReadyEvent
{
	public function __construct(
		public ContainerExtension $containerExtension,
		public ContainerInterface $container
	) {
	}
}
