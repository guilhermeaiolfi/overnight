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
		$app = $container->has(Application::class) ? $container->get(Application::class) : null;

		return new RestApiExecutor(
			$container,
			$container->get(RestApiConfig::class),
			$container->get(EventDispatcherInterface::class),
			$app instanceof Application ? $app : null,
		);
	}
}
