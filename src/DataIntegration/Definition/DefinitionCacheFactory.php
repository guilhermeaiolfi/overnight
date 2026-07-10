<?php

declare(strict_types=1);

namespace ON\DataIntegration\Definition;

use ON\Application;
use ON\DataIntegration\DataExtension;
use Psr\Container\ContainerInterface;

final class DefinitionCacheFactory
{
	public function __invoke(ContainerInterface $container): DefinitionCache
	{
		$app = $container->get(Application::class);

		return new DefinitionCache($app->ext(DataExtension::class)->getCacheFile());
	}
}
