<?php

declare(strict_types=1);

namespace ON\Router\Attribute;

use ON\Application;
use ON\Config\RouterConfig;
use ON\Config\Scanner\AttributeReader;

class RouteAttributeProcessor
{
	protected RouterConfig $config;

	public function __construct(
		protected Application $app
	) {
		$this->config = $app->config->get(RouterConfig::class);
	}

	public function __invoke(AttributeReader $reader): void
	{
		$attributes = $reader->getAttributes([], [Route::class]);
		foreach ($attributes as $attribute) {
			/** @var Route $attr */
			$this->config->addRoute(
				$attribute->getPath(),
				$attribute->__declaringClass . "::" . $attribute->__methodName,
				empty($attribute->getMethods()) ? null : $attribute->getMethods(),
				$attribute->getName()
			);
		}
	}
}
