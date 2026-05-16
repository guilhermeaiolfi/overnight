<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\DB\DatabaseManager;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Resolver\RestResolverInterface;
use ON\RestApi\Resolver\Sql\SqlFilterParser;
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
		$database = $manager->getDatabase();

		if ($database === null) {
			throw RestApiError::serviceUnavailable();
		}

		$defaultLimit = $config->get('defaultLimit', 100);
		$maxLimit = $config->get('maxLimit', 1000);

		return new SqlRestResolver(
			$registry,
			$database,
			new SqlFilterParser(),
			$defaultLimit,
			$maxLimit
		);
	}
}
