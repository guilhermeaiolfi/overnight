<?php
namespace ON;

use Mezzio\Router\RouterInterface;
use Psr\Container\ContainerInterface;

use const \Mezzio\DEFAULT_DELEGATE;
use const \Mezzio\DISPATCH_MIDDLEWARE;
use const \Mezzio\IMPLICIT_HEAD_MIDDLEWARE;
use const \Mezzio\IMPLICIT_OPTIONS_MIDDLEWARE;
use const \Mezzio\NOT_FOUND_MIDDLEWARE;
use const \Mezzio\ROUTE_MIDDLEWARE;
use Mezzio\Middleware\ErrorResponseGenerator;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\MiddlewareContainer;
use Mezzio\ApplicationPipeline;
use Mezzio\Router\AuraRouter;

use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

use ON\Router\RouterBridge;
use ON\Container\ExecutorInterface;
use ON\Middleware\RouteMiddleware;
use ON\Application;
use ON\Handler\NotFoundHandler;
use ON\Router\StatefulRouterInterface;

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
                //RouterInterface::class                        => Router\StatefulRouterInterface::class,
                //ContainerInterface::class                     => Container\InjectorContainer::class,
                //Container\ExecutorInterface::class            => Container\InjectorContainer::class,
                \Mezzio\MiddlewareFactory::class                    => \ON\Container\MiddlewareFactory::class,
                \Mezzio\Application::class                          => \ON\Application::class,


                DEFAULT_DELEGATE                                    => \Mezzio\Handler\NotFoundHandler::class,
                DISPATCH_MIDDLEWARE                                 => \Mezzio\Router\Middleware\DispatchMiddleware::class,
                IMPLICIT_HEAD_MIDDLEWARE                            => \Mezzio\Router\Middleware\ImplicitHeadMiddleware::class,
                IMPLICIT_OPTIONS_MIDDLEWARE                         => \Mezzio\Router\Middleware\ImplicitOptionsMiddleware::class,
                NOT_FOUND_MIDDLEWARE                                => \Mezzio\Handler\NotFoundHandler::class,
                ROUTE_MIDDLEWARE                                    => \ON\Middleware\RouteMiddleware::class,
                StatefulRouterInterface::class                      => \ON\Router\RouterBridge::class,

            ],
            'factories' => [
                Application::class                                  => \ON\ApplicationFactory::class,
                RouterBridge::class                                 => \ON\Router\RouterBridgeFactory::class,
                AuraRouter::class                                   => \ON\Router\RouterFactory::class,
                ExecutorInterface::class                            => \ON\Container\ExecutorFactory::class,
                RouteMiddleware::class        	                    => \ON\Container\RouteMiddlewareFactory::class,
                NotFoundHandler::class                              => \ON\Container\NotFoundHandlerFactory::class,

                ApplicationPipeline::class                          => \Mezzio\Container\ApplicationPipelineFactory::class,
                EmitterInterface::class                             => \Mezzio\Container\EmitterFactory::class,
                ErrorHandler::class                                 => \Mezzio\Container\ErrorHandlerFactory::class,
                MiddlewareContainer::class                          => \Mezzio\Container\MiddlewareContainerFactory::class,
                //\Mezzio\MiddlewareFactory::class                  => \Mezzio\Container\MiddlewareFactoryFactory::class,

                // Change the following in development to the WhoopsErrorResponseGeneratorFactory:
                ErrorResponseGenerator::class                       => \Mezzio\Container\ErrorResponseGeneratorFactory::class,
                ResponseInterface::class                            => \Mezzio\Container\ResponseFactoryFactory::class,
                RequestHandlerRunner::class                         => \Mezzio\Container\RequestHandlerRunnerFactory::class,


                ServerRequestErrorResponseGenerator::class          => \Mezzio\Container\ServerRequestErrorResponseGeneratorFactory::class,
                ServerRequestInterface::class                       => \Mezzio\Container\ServerRequestFactoryFactory::class,
                StreamInterface::class                              => \Mezzio\Container\StreamFactoryFactory::class,
            ]
        ];
     }
}