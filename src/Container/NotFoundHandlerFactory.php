<?php

declare(strict_types=1);

namespace ON\Container;

use ON\Config\AppConfig;
use ON\Handler\NotFoundHandler;
use ON\Middleware\PipelineExtension;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

class NotFoundHandlerFactory
{
	public function __invoke(ContainerInterface $container): NotFoundHandler
	{
		$config = $container->get(AppConfig::class);
		$pipeline = $container->get(PipelineExtension::class);

		$errorHandlerConfig = $config->get('controllers.errors.404');
		$path = is_string($errorHandlerConfig) ? $errorHandlerConfig : '';

		return new NotFoundHandler($pipeline, $path, $container->get(ResponseInterface::class));
	}
}
