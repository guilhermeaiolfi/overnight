<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Data\Definition\Registry;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Repository\ItemRepository;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

class ItemRepositoryFactory
{
	public function __invoke(ContainerInterface $container): ItemRepository
	{
		$config = $container->get(RestApiConfig::class);
		$registry = $container->get(Registry::class);

		if (! $container->has(DatabaseManager::class)) {
			throw RestApiError::serviceUnavailable();
		}

		$manager = $container->get(DatabaseManager::class);
		$cycle = $manager->getDatabase($config->get('database', 'cycle'));
		if (! $cycle instanceof CycleDatabase) {
			throw RestApiError::serviceUnavailable();
		}

		$database = $cycle->getConnection()->database($config->get('cycleDatabase', 'default'));

		return new ItemRepository(
			$registry,
			$database,
			$config->get('defaultLimit', 100),
			$config->get('maxLimit', 1000),
		);
	}
}
