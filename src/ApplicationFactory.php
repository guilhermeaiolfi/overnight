<?php
declare(strict_types=1);

namespace ON;


use ON\Application;
use Mezzio\ApplicationPipeline;
use ON\Container\MiddlewareFactory;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouterInterface;
use Psr\Container\ContainerInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;

/**
 * Create an Application instance.
 *
 * This class consumes three other services, and one pseudo-service (service
 * that looks like a class name, but resolves to a different resource):
 *
 * - Laminas\Expressive\MiddlewareFactory.
 * - Laminas\Expressive\ApplicationPipeline, which should resolve to a
 *   Laminas\Stratigility\MiddlewarePipeInterface instance.
 * - Laminas\Expressive\Router\RouteCollector.
 * - Laminas\HttpHandler\RequestHandlerRunner.
 */
class ApplicationFactory
{
    public function __invoke(ContainerInterface $container) : Application
    {
        return new Application(
            $container->get(MiddlewareFactory::class),
            $container->get(ApplicationPipeline::class),
            $container->get(RouteCollector::class),
            $container->get(RequestHandlerRunner::class)
        );
    }
}
