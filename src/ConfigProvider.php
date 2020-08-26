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
                RouterInterface::class                        => Router\StatefulRouterInterface::class,
                //ContainerInterface::class                     => Container\InjectorContainer::class,
                Container\ExecutorInterface::class            => Container\InjectorContainer::class,

                DEFAULT_DELEGATE            => Handler\NotFoundHandler::class,
                DISPATCH_MIDDLEWARE         => Router\Middleware\DispatchMiddleware::class,
                IMPLICIT_HEAD_MIDDLEWARE    => Router\Middleware\ImplicitHeadMiddleware::class,
                IMPLICIT_OPTIONS_MIDDLEWARE => Router\Middleware\ImplicitOptionsMiddleware::class,
                NOT_FOUND_MIDDLEWARE        => Handler\NotFoundHandler::class,
                ROUTE_MIDDLEWARE            => Router\Middleware\RouteMiddleware::class,

                // Legacy Zend Framework aliases
                \Zend\Expressive\Application::class => \Mezzio\Application::class,
                \Zend\Expressive\ApplicationPipeline::class => \Mezzio\ApplicationPipeline::class,
                \Zend\HttpHandlerRunner\Emitter\EmitterInterface::class => EmitterInterface::class,
                \Zend\Stratigility\Middleware\ErrorHandler::class => \Mezzio\ErrorHandler::class,
                \Zend\Expressive\Handler\NotFoundHandler::class => \Mezzio\Handler\NotFoundHandler::class,
                \Zend\Expressive\MiddlewareContainer::class => \Mezzio\MiddlewareContainer::class,
                \Zend\Expressive\MiddlewareFactory::class => \Mezzio\MiddlewareFactory::class,
                \Zend\Expressive\Middleware\ErrorResponseGenerator::class => \Mezzio\Middleware\ErrorResponseGenerator::class,
                \Zend\HttpHandlerRunner\RequestHandlerRunner::class => \Mezzio\RequestHandlerRunner::class,
                \Zend\Expressive\Response\ServerRequestErrorResponseGenerator::class => \Mezzio\Response\ServerRequestErrorResponseGenerator::class,
            ],
            'factories' => [
                Application::class                           => Container\ApplicationFactory::class,
                \Mezzio\ApplicationPipeline::class           => \Mezzio\Container\ApplicationPipelineFactory::class,
                Middleware\RouteMiddleware::class        	 => Middleware\RouteMiddlewareFactory::class,

                EmitterInterface::class                      => \Mezzio\Container\EmitterFactory::class,
                ErrorHandler::class                  => \Mezzio\Container\ErrorHandlerFactory::class,
                \Mezzio\Handler\NotFoundHandler::class       => \Mezzio\Container\NotFoundHandlerFactory::class,
                \Mezzio\MiddlewareContainer::class           => \Mezzio\Container\MiddlewareContainerFactory::class,
                \Mezzio\MiddlewareFactory::class             => \Mezzio\Container\MiddlewareFactoryFactory::class,

                // Change the following in development to the WhoopsErrorResponseGeneratorFactory:
                \Mezzio\Middleware\ErrorResponseGenerator::class => \Mezzio\Container\ErrorResponseGeneratorFactory::class,
                ResponseInterface::class                 =>     \Mezzio\Container\ResponseFactoryFactory::class,
                RequestHandlerRunner::class          => \Mezzio\Container\RequestHandlerRunnerFactory::class,


                \Mezzio\Response\ServerRequestErrorResponseGenerator::class  => \Mezzio\Container\ServerRequestErrorResponseGeneratorFactory::class,
                ServerRequestInterface::class            => \Mezzio\Container\ServerRequestFactoryFactory::class,
                StreamInterface::class                   => \Mezzio\Container\StreamFactoryFactory::class,
            ]
        ];
     }
}