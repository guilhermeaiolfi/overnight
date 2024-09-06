<?php
declare(strict_types=1);

namespace ON;


use ON\Application;
use ON\Container\MiddlewareFactory;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\RouterInterface;
use Psr\Container\ContainerInterface;

use Laminas\Stratigility\MiddlewarePipeInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;

/**
 * Create an Application instance.
 *
 * This class consumes three other services, and one pseudo-service (service
 * that looks like a class name, but resolves to a different resource):
 *
 */
class ApplicationFactory
{
    public function __invoke(ContainerInterface $container) : Application
    {
        return new Application(
            $container->get(MiddlewareFactory::class),
            $container->get(MiddlewarePipeInterface::class),
            $container->get(RouteCollectorInterface::class),
            $container->get(RequestHandlerRunner::class)
        );
    }
}
