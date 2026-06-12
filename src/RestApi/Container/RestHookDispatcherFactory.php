<?php

declare(strict_types=1);

namespace ON\RestApi\Container;

use ON\Container\Executor\ExecutorInterface;
use ON\RestApi\Hook\RestHookDispatcher;
use Psr\Container\ContainerInterface;

final class RestHookDispatcherFactory
{
	public function __invoke(ContainerInterface $container): RestHookDispatcher
	{
		return new RestHookDispatcher(
			$container,
			$container->get(ExecutorInterface::class),
		);
	}
}
