<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\DB\DatabaseManager;
use ON\DB\Cycle\CycleDatabase;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

final class CycleRecordLoaderFactory
{
	public function __invoke(ContainerInterface $container): CycleRecordLoader
	{
		$config = $container->get(RestApiConfig::class);

		if (! $container->has(DatabaseManager::class)) {
			throw RestApiError::serviceUnavailable();
		}

		$manager = $container->get(DatabaseManager::class);
		$cycle = $manager->getDatabase($config->get('database', 'cycle'));
		if (! $cycle instanceof CycleDatabase) {
			throw RestApiError::serviceUnavailable();
		}

		return new CycleRecordLoader($cycle->getResource());
	}
}
