<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\DB\DatabaseManager;
use ON\DB\Cycle\CycleDatabase;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\RestResolverInterface;
use ON\RestApi\Resolver\Sql\SqlRestResolver;
use ON\RestApi\RestApiConfig;
use Psr\Container\ContainerInterface;

class RestResolverFactory
{
	public function __invoke(ContainerInterface $container): RestResolverInterface
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

		return new SqlRestResolver(
			$registry,
			$database,
			$defaultLimit,
			$maxLimit
		);
	}
}
