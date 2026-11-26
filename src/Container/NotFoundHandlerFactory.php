<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Application;
use ON\Config\AppConfig;
use ON\Handler\NotFoundHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

class NotFoundHandlerFactory
{
	public function __invoke(ContainerInterface $container): NotFoundHandler
	{
		$config = $container->get(AppConfig::class);
		$app = $container->get(Application::class);

		$errorHandlerConfig = $config->get('controllers.errors.404');
		$path = is_string($errorHandlerConfig) ? $errorHandlerConfig : '';

		return new NotFoundHandler($app, $path, $container->get(ResponseInterface::class));
	}
}
