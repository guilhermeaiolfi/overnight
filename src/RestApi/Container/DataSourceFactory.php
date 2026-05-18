<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\DB\DatabaseManager;
use ON\DB\Cycle\CycleDatabase;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\DataSourceInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

class DataSourceFactory
{
	public function __invoke(ContainerInterface $container): DataSourceInterface
	{
		$config = $container->get(RestApiConfig::class);
		$registry = $container->get(Registry::class);

		if (!$container->has(DatabaseManager::class)) {
			throw RestApiError::serviceUnavailable();
		}

		$manager = $container->get(DatabaseManager::class);
		$cycle = $manager->getDatabase($config->get('database', 'cycle'));
		if (!$cycle instanceof CycleDatabase) {
			throw RestApiError::serviceUnavailable();
		}

		$database = $cycle->getConnection()->database($config->get('cycleDatabase', 'default'));
		$defaultLimit = $config->get('defaultLimit', 100);
		$maxLimit = $config->get('maxLimit', 1000);

		return new SqlDataSource(
			$registry,
			$database,
			$defaultLimit,
			$maxLimit
		);
	}
}
