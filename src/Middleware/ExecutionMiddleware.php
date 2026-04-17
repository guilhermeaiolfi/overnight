<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Container\Executor\ExecutorInterface;
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

		$target = $routeResult->getTargetInstance();
		$method = $routeResult->getMethod();

		// PSR-15 middleware: call process() directly
		if ($method === 'process' && $target instanceof MiddlewareInterface) {
			return $target->process($request, $handler);
		}

		// Page/controller action: resolve route params, execute, build view
		$args = $this->resolveRouteParams($target, $method, $request);
		$args[ServerRequestInterface::class] = $request;
		$args[RequestHandlerInterface::class] = $handler;

		$actionResponse = $this->executor->execute([$target, $method], $args);

		return $this->buildView($target, $method, $actionResponse, $request, $handler);
	}

	protected function resolveRouteParams(object $target, string $method, ServerRequestInterface $request): array
	{
		$args = [];
		$result = $request->getAttribute(RouteResult::class);

		if (! $result || ! $result->isSuccess()) {
			return $args;
		}

		$routeParams = $result->getMatchedParams();
		$reflection = new ReflectionMethod($target, $method);

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
