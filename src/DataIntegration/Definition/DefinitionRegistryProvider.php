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
		$event = $container->get(Application::class)->init()->emit(new DataDefinitionConfigureEvent($registry));
		$definitions = $event->definitions();

		return new Registry($definitions);
	}
}
