<?php
namespace ON\Container;

use Psr\Container\ContainerInterface;
use Mezzio\Router\RouterInterface;
use ON\Container\Executor;

class ExecutorFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $executor = new Executor(null, $container);

        //$containerResolver = new TypeHintContainerResolver($container);
        // or
        $containerResolver = new \Invoker\ParameterResolver\Container\ParameterNameContainerResolver($container);

        $executor->getParameterResolver()->prependResolver($containerResolver);

        return $executor;
    }
}