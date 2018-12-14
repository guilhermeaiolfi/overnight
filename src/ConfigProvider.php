<?php
namespace ON;

use Zend\Expressive\Router\RouterInterface;
use Psr\Container\ContainerInterface;

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
                ContainerInterface::class                     => Container\InjectorContainer::class,
                Container\ExecutorInterface::class            => Container\InjectorContainer::class
            ],
            'factories' => [
                Application::class                           => Container\ApplicationFactory::class,
                Middleware\RouteMiddleware::class        	 => Middleware\RouteMiddlewareFactory::class
            ]
        ];
     }
}