<?php

declare(strict_types=1);

namespace ON\Container\Executor;

use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use Psr\Container\ContainerInterface;

class ExecutorFactory
{
	public function __invoke(ContainerInterface $container)
	{

		$parameterResolver = new ResolverChain([
			new TypeHintResolver(),
			new NumericArrayResolver(),
			new AssociativeArrayResolver(),
			new DefaultValueResolver(),
			new TypeHintContainerResolver($container),
		]);
		$executor = new Executor($parameterResolver, $container);

		/*$containerResolver = new TypeHintContainerResolver($container);
		// or
		//$containerResolver = new \Invoker\ParameterResolver\Container\ParameterNameContainerResolver($container);

		$executor->getParameterResolver()->prependResolver($containerResolver);*/

		return $executor;
	}
}
