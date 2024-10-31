<?php

declare(strict_types=1);

namespace ON\Container\Executor;

use Psr\Container\ContainerInterface;

class ExecutorFactory
{
	public function __invoke(ContainerInterface $container)
	{
		$executor = new Executor(null, $container);

		$containerResolver = new TypeHintContainerResolver($container);
		// or
		//$containerResolver = new \Invoker\ParameterResolver\Container\ParameterNameContainerResolver($container);

		$executor->getParameterResolver()->prependResolver($containerResolver);

		return $executor;
	}
}
