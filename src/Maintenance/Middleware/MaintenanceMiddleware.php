<?php

declare(strict_types=1);

namespace ON\Maintenance\Middleware;

use ON\Application;
use ON\Config\AppConfig;
use ON\Container\Executor\ExecutorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MaintenanceMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Application $app
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->app->isMaintenanceMode()) {
			$appCfg = $this->app->config->get(AppConfig::class);
			$middleware = $appCfg->get("controllers.maintenance");
			$method = null;
			if (str_contains($middleware, "::")) {
				[$middleware, $method] = explode("::", $middleware);
			}
			$instance = $this->app->container->get($middleware);
			$executor = $this->app->container->get(ExecutorInterface::class);
			if ($method) {
				$result = $executor->execute(
					[ $instance, $method ],
					[
						ServerRequestInterface::class => $request,
						RequestHandlerInterface::class => $handler,
					]
				);

				return $result;
			}

			return $executor->execute(
				$instance,
				[
					ServerRequestInterface::class => $request,
					RequestHandlerInterface::class => $handler,
				]
			);
		}

		return $handler->handle($request, $handler);
	}
}
