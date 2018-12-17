<?php
declare(strict_types=1);

namespace ON\Container;

use ON\Application;
use Psr\Container\ContainerInterface;
use Zend\Expressive\ApplicationPipeline;
use ON\Container\MiddlewareFactory;
use Zend\Expressive\Router\RouteCollector;
use Zend\Expressive\Router\RouterInterface;
use Zend\HttpHandlerRunner\RequestHandlerRunner;

/**
 * Create an Application instance.
 *
 * This class consumes three other services, and one pseudo-service (service
 * that looks like a class name, but resolves to a different resource):
 *
 * - Zend\Expressive\MiddlewareFactory.
 * - Zend\Expressive\ApplicationPipeline, which should resolve to a
 *   Zend\Stratigility\MiddlewarePipeInterface instance.
 * - Zend\Expressive\Router\RouteCollector.
 * - Zend\HttpHandler\RequestHandlerRunner.
 */
class ApplicationFactory
{
    public function __invoke(ContainerInterface $container) : Application
    {
        return new Application(
            $container->get(MiddlewareFactory::class),
            $container->get(ApplicationPipeline::class),
            $container->get(RouteCollector::class),
            $container->get(RequestHandlerRunner::class),
            $container->get(RouterInterface::class)
        );
    }
}
