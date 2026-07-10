<?php

declare(strict_types=1);

namespace ON\DataIntegration\Definition;

use ON\Application;
use ON\Data\Definition\Registry;
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use Psr\Container\ContainerInterface;

final class DefinitionRegistryProvider
{
	public function __invoke(ContainerInterface $container): Registry
	{
		$cache = $container->get(DefinitionCache::class);

		if ($cache->exists()) {
			return new Registry($cache->load());
		}

		$registry = new Registry();

		$container
			->get(Application::class)
			->init()
			->emit(new DataDefinitionConfigureEvent($registry));

		$definitions = $registry->all();

		$cache->write($definitions);

		return new Registry($definitions);
	}
}
