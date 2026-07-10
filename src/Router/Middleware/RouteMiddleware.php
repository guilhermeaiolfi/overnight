<?php

declare(strict_types=1);

namespace ON\Router\Middleware;

use ON\Application;
use ON\Http\InvocationContext;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Matches the current request and prepares the execution target for downstream
 * middleware. If a RouteResult is already present, we assume another middleware
 * already prepared the request and simply continue the pipeline.
 *
 * @internal
 */
class RouteMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected RouterInterface $router,
		protected Application $app,
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
		if ($request->getAttribute(RouteResult::class)) {
			return $handler->handle($request);
		}

		$result = $this->router->match($request);
		if ($result->isFailure()) {
			return $handler->handle($request);
		}

		$request = $request->withAttribute(RouteResult::class, $result);

		$options = $result->getMatchedRoute()->getOptions();
		if (! empty($options['callbacks']) && is_array($options['callbacks'])) {
			foreach ($options['callbacks'] as $callback) {
				$callback = $this->container->get($callback);
				$result = $callback->onMatched($result);
			}
		}

		$request = $request->withAttribute(RouteResult::class, $result);

		foreach ($result->getMatchedParams() as $param => $value) {
			$request = $request->withAttribute($param, $value);
		}

		$request = $this->prepareTarget($request, $result);
		$request = $this->enrichInvocationContextFromRouteParams($request, $result);

		return $handler->handle($request);
	}

	private function prepareTarget(ServerRequestInterface $request, RouteResult $result): ServerRequestInterface
	{
		$middleware = $result->getMatchedRoute()->getMiddleware();

		if (is_string($middleware) && strpos($middleware, '::') !== false) {
			[$className, $method] = explode('::', $middleware);
			$result->setTargetInstance($this->container->get($className));
			$result->setMethod($method);
		} else {
			$prepared = isset($this->app->pipeline)
				? $this->app->pipeline->prepareMiddleware($middleware)
				: $middleware;

			$result->setTargetInstance($prepared);
			$result->setMethod('process');
		}

		return $request->withAttribute(RouteResult::class, $result);
	}

	private function enrichInvocationContextFromRouteParams(
		ServerRequestInterface $request,
		RouteResult $result
	): ServerRequestInterface {
		$target = $result->getTargetInstance();

		if (! $target || ! $result->isSuccess()) {
			return $request;
		}

		$routeParams = $result->getMatchedParams();

		if ($routeParams === []) {
			return $request;
		}

		$context = InvocationContext::fromRequest($request);

		foreach ($routeParams as $name => $value) {
			if (is_string($name)) {
				$context = $context->with($name, $value);
			}
		}

		$reflection = new ReflectionMethod($target, $result->getMethod());

		foreach ($reflection->getParameters() as $param) {
			$paramName = $param->getName();

			if (! array_key_exists($paramName, $routeParams)) {
				continue;
			}

			$value = $routeParams[$paramName];
			$type = $param->getType();

			if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
				settype($value, $type->getName());
			} elseif ($type && ! $type->isBuiltin()) {
				continue;
			}

			$context = $context->with($paramName, $value);
		}

		return $request->withAttribute(InvocationContext::class, $context);
	}
}
