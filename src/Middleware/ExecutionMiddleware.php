<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Container\Executor\ExecutorInterface;
use ON\Http\InvocationContext;
use ON\Router\RouterInterface;
use ON\Router\RouteResult;
use ON\View\ViewManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ExecutionMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected RouterInterface $router,
		protected ExecutorInterface $executor,
		protected ViewManager $viewManager
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);

		if (! $routeResult) {
			return $handler->handle($request);
		}

		$target = $routeResult->getTargetInstance();
		$method = $routeResult->getMethod();

		// PSR-15 middleware: call process() directly
		if ($method === 'process' && $target instanceof MiddlewareInterface) {
			return $target->process($request, $handler);
		}

		// Page/controller action: consume prepared invocation context and execute.
		$args = [];
		$args = InvocationContext::fromRequest($request)->applyToArgs($args);
		$args[ServerRequestInterface::class] = $request;
		$args[RequestHandlerInterface::class] = $handler;

		$actionResponse = $this->executor->execute([$target, $method], $args);

		return $this->viewManager->runView($target, $method, $actionResponse, $request, $handler, $this->executor);
	}
}
