<?php
namespace ON;

use Mezzio\Router\RouterInterface as MezzioRouter;
use Psr\Container\ContainerInterface;

use Mezzio\Middleware\ErrorResponseGenerator;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\MiddlewareContainer;

use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\RouterInterface as MezzioRouterInterface;

use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;

use Laminas\Stratigility\Middleware\ErrorHandler;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mezzio\Router\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

use ON\Middleware\RouteMiddleware;
use ON\Application;
use ON\Auth\AuthenticationServiceInterface;
use ON\Handler\NotFoundHandler;
use ON\Router\RouterInterface;
use ON\Router\Router;
use ON\Router\FastRouteRouter;
use Psr\Http\Server\RequestHandlerInterface;

use ON\Container\Executor\ExecutorInterface;

class ConfigProvider
{
    /**
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    /**
     * @return array
     */
    public function getDependencies()
    {
         return [
            'aliases' =>[
                RouteCollectorInterface::class                      => \Mezzio\Router\RouteCollector::class,
                RouterInterface::class                              => \ON\Router\Router::class,
                MiddlewarePipeInterface::class                      => \Laminas\Stratigility\MiddlewarePipe::class,
                MiddlewareFactoryInterface::class                   => \ON\Container\MiddlewareFactory::class,
                RouteCollectorInterface::class                      => \Mezzio\Router\RouteCollector::class,
                MezzioRouterInterface::class                        => \ON\Router\Router::class,
                //RequestHandlerInterface::class                      => \ON\Application::class
            ],
            'factories' => [
                Application::class                                  => \ON\ApplicationFactory::class,
                Router::class                                       => \ON\Router\RouterFactory::class,
                ExecutorInterface::class                            => \ON\Container\Executor\ExecutorFactory::class,
                RouteMiddleware::class                              => \ON\Container\RouteMiddlewareFactory::class,
                NotFoundHandler::class                              => \ON\Container\NotFoundHandlerFactory::class,
                RouterInterface::class                              => \ON\Router\RouterFactory::class,

                RouteCollector::class                               => \Mezzio\Router\RouteCollectorFactory::class,


                EmitterInterface::class                             => \Mezzio\Container\EmitterFactory::class,
                ErrorHandler::class                                 => \Mezzio\Container\ErrorHandlerFactory::class,
                MiddlewareContainer::class                          => \Mezzio\Container\MiddlewareContainerFactory::class,

                // Change the following in development to the WhoopsErrorResponseGeneratorFactory:
                ErrorResponseGenerator::class                       => \Mezzio\Container\ErrorResponseGeneratorFactory::class,
                ResponseInterface::class                            => \Mezzio\Container\ResponseFactoryFactory::class,
                RequestHandlerRunner::class                         => \ON\Container\RequestHandlerRunnerFactory::class,


                ServerRequestErrorResponseGenerator::class          => \Mezzio\Container\ServerRequestErrorResponseGeneratorFactory::class,
                ServerRequestInterface::class                       => \Mezzio\Container\ServerRequestFactoryFactory::class,
                StreamInterface::class                              => \Mezzio\Container\StreamFactoryFactory::class

                
            ]
        ];
     }
}