<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Application;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiExecutor;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class RestApiExecutorFactory
{
	public function __invoke(ContainerInterface $container): RestApiExecutor
	{
		$config = $container->get(RestApiConfig::class);
		$config->registerDefaultActions();

		return new RestApiExecutor(
			$container,
			$config,
			$container->get(EventDispatcherInterface::class),
			$container->has(Application::class) ? $container->get(Application::class) : null,
		);
	}
}
