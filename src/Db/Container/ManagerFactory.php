<?php

declare(strict_types=1);

namespace ON\Db\Container;

use Exception;
use ON\Db\Config\DatabaseConfig;
use ON\Db\Manager;
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

		$settings = $config;

		$manager = new Manager($settings, $container);

		$manager->setEventDispatcher($container->get(EventDispatcherInterface::class));

		return $manager;
	}
}
