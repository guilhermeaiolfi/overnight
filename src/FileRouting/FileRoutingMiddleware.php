<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FileRoutingMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected ViewConfig $viewCfg,
		protected FileRoutingConfig $fileRoutingConfig,
		protected RouterInterface $router,
		protected ContainerInterface $container
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param DelegateInterface $delegate
	 * @return ResponseInterface
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$fileRouter = new FileRouter($this->fileRoutingConfig, $this->router->getBasePath());

		// if we already set the route, don't handle it again
		if ($request->getAttribute(RouteResult::class)) {
			return $handler->handle($request);
		}

		$result = $fileRouter->match($request);

		// only intercept if there is a file to handle it
		if ($result->isFailure()) {
			return $handler->handle($request);
		}

		foreach ($result->getMatchedParams() as $param => $value) {
			$request = $request->withAttribute($param, $value);
		}

		[$className, $method] = explode('::', (string) $this->fileRoutingConfig->get('controller'));
		$result->setTargetInstance($this->container->get($className));
		$result->setMethod($method);
		$request = $request->withAttribute(RouteResult::class, $result);

		return $handler->handle($request);
	}
}
