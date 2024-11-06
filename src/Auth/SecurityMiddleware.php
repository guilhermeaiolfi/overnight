<?php

declare(strict_types=1);

namespace ON\Auth;

use ON\Router\ActionMiddlewareDecorator;
use ON\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecurityMiddleware implements MiddlewareInterface
{
	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);

		if (! $routeResult) {
			return $handler->handle($request);
		}


		$route = $routeResult->getMatchedRoute();
		$middleware = $route->getMiddleware();

		if ($middleware instanceof ActionMiddlewareDecorator) {
			return $middleware->loggedCheck($request, $handler);
		}

		return $handler->handle($request);
	}
}
