<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\ORM\Definition\Registry;
use ON\RestApi\Resolver\Sql\SqlDataSource;
use ON\RestApi\Resolver\Sql\SqlQuerySpecCompiler;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiService;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiServiceFactory
{
	public function __invoke(ContainerInterface $container): RestApiService
	{
		$dataSource = $container->get(SqlDataSource::class);
		$config = $container->get(RestApiConfig::class);

		return new RestApiService(
			$container->get(Registry::class),
			$dataSource,
			new SqlQuerySpecCompiler(
				$dataSource->getDatabase(),
				$config->get('defaultLimit', 100),
				$config->get('maxLimit', 1000)
			),
			$container->get(EventDispatcherInterface::class)
		);
	}
}
