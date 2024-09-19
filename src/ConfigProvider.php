<?php
namespace ON;

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
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\MethodNotAllowedMiddleware;
use Mezzio\Router\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

use ON\Middleware\RouteMiddleware;
use ON\Application;
use ON\Auth\AuthorizationServiceInterface;
use ON\Config\ConfigInterface;
use ON\Handler\NotFoundHandler;
use ON\Router\RouterInterface;
use ON\Router\Router;

use ON\Container\Executor\ExecutorInterface;
use ON\Db\Manager;
use ON\Service\RoutesLoader;
use Psr\EventDispatcher\EventDispatcherInterface;

class ConfigProvider
{
    /**
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencies(),
            'app' => [
                // use null to be automatically detected
                "a" => true,
                'basepath' => null,
                'project_dir' => getcwd(),
                'cache_dir' => 'var/cache',
                'data_dir' => 'var/data',
                'log_dir' => 'var/log',
                'config_dir' => 'config',
                'pipeline_file' => '%app.config_dir%/pipeline.php',
                'routes_file' => '%app.config_dir%/routes.php'
            ]
        ];
    }

    /**
     * @return array
     */
    public function getDependencies()
    {
         return [
            'autowires' => [
                
            ],
            'aliases' =>[
                RouteCollectorInterface::class                      => \Mezzio\Router\RouteCollector::class,
                RouterInterface::class                              => \ON\Router\Router::class,
                MiddlewarePipeInterface::class                      => \Laminas\Stratigility\MiddlewarePipe::class,
                MiddlewareFactoryInterface::class                   => \ON\Container\MiddlewareFactory::class,
                MezzioRouterInterface::class                        => \ON\Router\Router::class,
                EventDispatcherInterface::class                     => \ON\Event\EventDispatcher::class,
            ],
            'delegators' => [],
            'factories' => [
                Manager::class                                      => \ON\Db\ManagerFactory::class,
                Router::class                                       => \ON\Router\RouterFactory::class,
                ExecutorInterface::class                            => \ON\Container\Executor\ExecutorFactory::class,
                NotFoundHandler::class                              => \ON\Container\NotFoundHandlerFactory::class,
                
                EmitterInterface::class                             => \Mezzio\Container\EmitterFactory::class,
                ErrorHandler::class                                 => \Mezzio\Container\ErrorHandlerFactory::class,
                MiddlewareContainer::class                          => \Mezzio\Container\MiddlewareContainerFactory::class,
                
                // Change the following in development to the WhoopsErrorResponseGeneratorFactory:
                ErrorResponseGenerator::class                       => \Mezzio\Container\ErrorResponseGeneratorFactory::class,
                ResponseInterface::class                            => \Mezzio\Container\ResponseFactoryFactory::class,
                RequestHandlerRunner::class                         => \ON\Container\RequestHandlerRunnerFactory::class,
                
                ServerRequestErrorResponseGenerator::class          => \Mezzio\Container\ServerRequestErrorResponseGeneratorFactory::class,
                ServerRequestInterface::class                       => \Mezzio\Container\ServerRequestFactoryFactory::class,
                StreamInterface::class                              => \Mezzio\Container\StreamFactoryFactory::class,
                
                // Router
                RouteMiddleware::class                              => \ON\Container\RouteMiddlewareFactory::class,
                RouterInterface::class                              => \ON\Router\RouterFactory::class,
                RouteCollector::class                               => \Mezzio\Router\RouteCollectorFactory::class,
                DispatchMiddleware::class                           => \Mezzio\Router\Middleware\DispatchMiddlewareFactory::class,
                ImplicitHeadMiddleware::class                       => \Mezzio\Router\Middleware\ImplicitHeadMiddlewareFactory::class,
                ImplicitOptionsMiddleware::class                    => \Mezzio\Router\Middleware\ImplicitOptionsMiddlewareFactory::class,
                MethodNotAllowedMiddleware::class                   => \Mezzio\Router\Middleware\MethodNotAllowedMiddlewareFactory::class,
            ]
        ];
     }
}