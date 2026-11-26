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
		$app = $container->get(Application::class);
		$cache = $container->get(DefinitionCache::class);

		if (! $app->isDebug() && $cache->exists()) {
			return new Registry($cache->load());
		}

		return $this->build($app, $app->isDebug() ? null : $cache);
	}

	public function warm(ContainerInterface $container): Registry
	{
		return $this->build(
			$container->get(Application::class),
			$container->get(DefinitionCache::class),
		);
	}

	private function build(Application $app, ?DefinitionCache $cache): Registry
	{
		$registry = new Registry();
		$app->init()->emit(new DataDefinitionConfigureEvent($registry));

		$definitions = $registry->all();
		$cache?->write($definitions);

		return new Registry($definitions);
	}
}
