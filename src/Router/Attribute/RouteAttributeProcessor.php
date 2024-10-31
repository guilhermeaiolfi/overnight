<?php

namespace ON\Router\Attribute;

use ON\Application;
use ON\Config\RouterConfig;

class RouteAttributeProcessor
{
    protected RouterConfig $config;
    public function __construct(
        protected Application $app
    )
    {
        $this->config = $app->config->get(RouterConfig::class);
    }
    
    public function __invoke($attributes): void
    {
        $attributes = $attributes[Route::class];
        foreach ($attributes as $className => $methods) {
			foreach ($methods as $methodName => $attributes) {
				foreach ($attributes as $attr) {
					/** @var Route $attr */
					//$this->app->router->route($attr->getPath(), $className . "::" . $methodName, empty($attr->getMethods()) ? null : $attr->getMethods(), $attr->getName());
					$this->config->addRoute($attr->getPath(), $className . "::" . $methodName, empty($attr->getMethods()) ? null : $attr->getMethods(), $attr->getName());
				}
			}
		}
    }
}