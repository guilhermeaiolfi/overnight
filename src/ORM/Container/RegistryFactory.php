<?php

declare(strict_types=1);

namespace ON\ORM\Container;

use ON\Application;
use ON\ORM\Definition\Registry;
use ON\ORM\Init\Event\OrmConfigureEvent;
use Psr\Container\ContainerInterface;

class RegistryFactory
{
	public function __invoke(ContainerInterface $container): Registry
	{
		$app = $container->get(Application::class);

		// Check if a Registry was already provided via Configuration (e.g., in orm.all.php)
		if ($app->config->has(Registry::class)) {
			$registry = $app->config->get(Registry::class);
		} else {
			$registry = new Registry();
		}

		// Emit the CONFIGURE event so modules can register their collections.
		// This happens even if a registry was provided via config, allowing late-binding extensions to run.
		$app->init()->emit(new OrmConfigureEvent($registry));

		return $registry;
	}
}
