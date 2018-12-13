<?php
namespace ON;

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
            'factories' => [
                Middleware\RouteMiddleware::class        	 => Middleware\RouteMiddlewareFactory::class
            ]
        ];
     }
}