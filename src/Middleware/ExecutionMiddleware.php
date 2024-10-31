<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Container\Executor\ExecutorInterface;
use ON\Router\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ExecutionMiddleware implements MiddlewareInterface
{
	protected $container = null;
	protected $executor = null;

	public function __construct(ContainerInterface $container, ExecutorInterface $executor)
	{
		$this->container = $container;
		$this->executor = $executor;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);
		if (! $routeResult) {

			return $handler->handle($request);
		}

		$middleware = $routeResult->getMatchedRoute()->getMiddleware();

		return $middleware->process($request, $handler);
	}
}
