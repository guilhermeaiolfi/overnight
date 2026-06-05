<?php

declare(strict_types=1);

namespace ON\Event\Container;

use ON\Application;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class EventDispatcherFactory
{
	public function __invoke(ContainerInterface $container): EventDispatcherInterface
	{
		return $container->get(Application::class)->events->eventDispatcher;
	}
}
