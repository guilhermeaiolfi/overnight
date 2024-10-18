<?php
namespace ON\Container;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ON\Router\Exception\MissingDependencyException;
use ON\Router\Middleware\ImplicitHeadMiddleware;
use ON\Router\RouterInterface;
use ON\Middleware\RouteMiddleware;
use ON\RequestStack;

/**
 * Create and return a RouteMiddleware instance.
 *
 * This factory depends on one other service:
 *
 * - ON\Router\RouterInterface, which should resolve to
 *   a class implementing that interface.
 * - Psr\Http\Message\ResponseInterface, which should resolve to an instance
 *   implementing that interface. NOTE: in version 3, this should resolve to a
 *   callable instead. This factory supports both styles.
 */
class RouteMiddlewareFactory
{
    /**
     * @return RouteMiddleware
     * @throws MissingDependencyException if the RouterInterface service is
     *     missing.
     */
    public function __invoke(ContainerInterface $container)
    {
        if (! $container->has(RouterInterface::class)) {
            throw MissingDependencyException::dependencyForService(
                RouterInterface::class,
                RouteMiddleware::class
            );
        }

        if (! $container->has(ResponseInterface::class)) {
            throw MissingDependencyException::dependencyForService(
                ResponseInterface::class,
                ImplicitHeadMiddleware::class
            );
        }

        // If the response service resolves to a callable factory, call it to
        // resolve to an instance.
        $response = $container->get(ResponseInterface::class);
        if (! $response instanceof ResponseInterface && is_callable($response)) {
            $response = $response();
        }
        return new RouteMiddleware(
            $container->get(RouterInterface::class), 
            $container->get(RequestStack::class), 
            $container
        );
    }
}
