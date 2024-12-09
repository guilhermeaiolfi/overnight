<?php

declare(strict_types=1);

namespace ON\DB\Container;

use Exception;
use ON\DB\DatabaseConfig;
use ON\DB\Manager;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class ManagerFactory
{
	public function __invoke(ContainerInterface $container): Manager
	{
		if (! $container->has(DatabaseConfig::class)) {
			throw new Exception("There is no DatabaseConfig set");
		}
		$config = $container->get(DatabaseConfig::class);

		$manager = new Manager($config, $container);

		$manager->setEventDispatcher($container->get(EventDispatcherInterface::class));

		return $manager;
	}
}
