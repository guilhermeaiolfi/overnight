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


use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

use ON\Router\RouterBridge;

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
                \Mezzio\MiddlewareFactory::class              => \ON\Container\MiddlewareFactory::class,
                \Mezzio\Application::class                           => Application::class,

                DEFAULT_DELEGATE            => Handler\NotFoundHandler::class,
                DISPATCH_MIDDLEWARE         => Router\Middleware\DispatchMiddleware::class,
                IMPLICIT_HEAD_MIDDLEWARE    => Router\Middleware\ImplicitHeadMiddleware::class,
                IMPLICIT_OPTIONS_MIDDLEWARE => Router\Middleware\ImplicitOptionsMiddleware::class,
                NOT_FOUND_MIDDLEWARE        => Handler\NotFoundHandler::class,
                ROUTE_MIDDLEWARE            => Router\Middleware\RouteMiddleware::class,

            ],
            'factories' => [
                Application::class                           => \ON\ApplicationFactory::class,

                RouterBridge::class                         => \ON\Router\RouterBridgeFactory::class,
                \Mezzio\Router\AuraRouter::class            => \ON\Router\RouterFactory::class,
                \Mezzio\ApplicationPipeline::class           => \Mezzio\Container\ApplicationPipelineFactory::class,
                Middleware\RouteMiddleware::class        	 => Middleware\RouteMiddlewareFactory::class,

                EmitterInterface::class                      => \Mezzio\Container\EmitterFactory::class,
                ErrorHandler::class                          => \Mezzio\Container\ErrorHandlerFactory::class,
                \Mezzio\Handler\NotFoundHandler::class       => \Mezzio\Container\NotFoundHandlerFactory::class,
                \Mezzio\MiddlewareContainer::class           => \Mezzio\Container\MiddlewareContainerFactory::class,
                //\Mezzio\MiddlewareFactory::class             => \Mezzio\Container\MiddlewareFactoryFactory::class,

                // Change the following in development to the WhoopsErrorResponseGeneratorFactory:
                \Mezzio\Middleware\ErrorResponseGenerator::class => \Mezzio\Container\ErrorResponseGeneratorFactory::class,
                ResponseInterface::class                    =>     \Mezzio\Container\ResponseFactoryFactory::class,
                RequestHandlerRunner::class                 => \Mezzio\Container\RequestHandlerRunnerFactory::class,


                \Mezzio\Response\ServerRequestErrorResponseGenerator::class  => \Mezzio\Container\ServerRequestErrorResponseGeneratorFactory::class,
                ServerRequestInterface::class            => \Mezzio\Container\ServerRequestFactoryFactory::class,
                StreamInterface::class                   => \Mezzio\Container\StreamFactoryFactory::class,
                \ON\Container\ExecutorInterface::class  => \ON\Container\ExecutorFactory::class
            ]
        ];
     }
}