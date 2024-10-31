<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Action;
use ON\Application;
use ON\RequestStack;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ActionInjectionMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Application $app,
		protected ContainerInterface $container,
		protected RequestStack $stack
	) {
	}

	public function process(ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);

		if (! $routeResult) {
			return $handler->handle($request, $handler);
		}

		$middleware = $routeResult->getMatchedRoute()->getMiddleware();
		if (is_string($middleware)) {
			$middleware = $this->app->pipeline->prepareMiddleware($middleware);
			$routeResult->getMatchedRoute()->setMiddleware($middleware);
		}

		$action = null;

		if ($middleware instanceof ActionMiddlewareDecorator && strpos($middleware->middlewareName, "::") !== false) {
			$action = new Action($middleware->middlewareName);

			$instance = $this->container->get($action->getClassName());

			$action->setPageInstance($instance);

			/*$old_request = $request;

			$request = $request->withAttribute(Action::class, $action);

			$this->stack->update($old_request, $request);*/

			$routeResult->set(Action::class, $action);
		}

		return $handler->handle($request, $handler);
	}
}
