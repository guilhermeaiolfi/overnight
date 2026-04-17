<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Container\Executor\ExecutorInterface;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\RouteResult;
use ON\View\ViewBuilderTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use ReflectionNamedType;

class ExecutionMiddleware implements MiddlewareInterface
{
	use ViewBuilderTrait;

	public function __construct(
		protected ContainerInterface $container,
		protected ExecutorInterface $executor
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);

		if (! $routeResult) {
			return $handler->handle($request);
		}

		$middleware = $routeResult->getMatchedRoute()->getMiddleware();

		// ActionMiddlewareDecorator: run the page action + view builder
		if ($middleware instanceof ActionMiddlewareDecorator) {
			return $this->execute($middleware, $request, $handler);
		}

		// Regular PSR-15 middleware: delegate directly
		if ($middleware instanceof MiddlewareInterface) {
			return $middleware->process($request, $handler);
		}

		return $handler->handle($request);
	}

	protected function execute(ActionMiddlewareDecorator $decorator, ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$page = $decorator->getPageInstance();
		$method = $decorator->getMethod();

		$args = $this->resolveRouteParams($page, $method, $request);
		$args[ServerRequestInterface::class] = $request;
		$args[RequestHandlerInterface::class] = $handler;

		$actionResponse = $this->executor->execute([$page, $method], $args);

		return $this->buildView($page, $method, $actionResponse, $request, $handler);
	}

	protected function resolveRouteParams(object $page, string $method, ServerRequestInterface $request): array
	{
		$args = [];
		$result = $request->getAttribute(RouteResult::class);

		if (! $result || ! $result->isSuccess()) {
			return $args;
		}

		$routeParams = $result->getMatchedParams();
		$reflection = new ReflectionMethod($page, $method);

		foreach ($reflection->getParameters() as $param) {
			$paramName = $param->getName();

			if (isset($routeParams[$paramName])) {
				$type = $param->getType();

				if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
					$value = $routeParams[$paramName];
					settype($value, $type->getName());
					$args[$paramName] = $value;
				} elseif (! $type) {
					$args[$paramName] = $routeParams[$paramName];
				}
			}
		}

		return $args;
	}
}
