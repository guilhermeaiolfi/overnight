<?php

declare(strict_types=1);

namespace ON\DB\Container;

use Exception;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseManager;
use ON\DB\DatabaseStore;
use ON\DB\DatabaseStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class DatabaseManagerFactory
{
	public function __invoke(ContainerInterface $container): DatabaseManager
	{
		if (! $container->has(DatabaseConfig::class)) {
			throw new Exception("There is no DatabaseConfig set");
		}
		$config = $container->get(DatabaseConfig::class);
		$store = $container->has(DatabaseStoreInterface::class)
			? $container->get(DatabaseStoreInterface::class)
			: new DatabaseStore();
		$manager = new DatabaseManager($config, $container, $store);

		$manager->setEventDispatcher($container->get(EventDispatcherInterface::class));

		return $manager;
	}
}
