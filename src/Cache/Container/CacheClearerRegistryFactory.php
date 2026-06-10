<?php

declare(strict_types=1);

namespace ON\Cache\Container;

use ON\Application;
use ON\Cache\CacheClearerRegistry;
use ON\Cache\CacheExtension;
use Psr\Container\ContainerInterface;

final class CacheClearerRegistryFactory
{
	public function __invoke(ContainerInterface $container): CacheClearerRegistry
	{
		return $container->get(Application::class)
			->ext(CacheExtension::class)
			->getClearerRegistry();
	}
}
