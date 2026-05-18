<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\ORM\Definition\Registry;
use ON\RestApi\Resolver\DataSourceInterface;
use ON\RestApi\RestApiService;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RestApiServiceFactory
{
	public function __invoke(ContainerInterface $container): RestApiService
	{
		return new RestApiService(
			$container->get(Registry::class),
			$container->get(DataSourceInterface::class),
			$container->get(EventDispatcherInterface::class)
		);
	}
}
