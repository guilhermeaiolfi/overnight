<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Application;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
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
		protected Application $app
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

		$request = $this->app->pipeline->prepareRequestFromRouteResult($result, $request);

		return $handler->handle($request);

	}
}
