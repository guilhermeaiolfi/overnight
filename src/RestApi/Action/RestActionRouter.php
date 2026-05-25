<?php

declare(strict_types=1);

namespace ON\RestApi\Action;

use FastRoute\DataGenerator\GroupCountBased as RouteGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use ON\Router\Route;
use ON\Router\RouteResult;

final class RestActionRouter
{
	/** @param list<array{name: string, methods: list<string>, path: string, action: string, options?: array<string, mixed>}> $routes */
	public function __construct(private array $routes)
	{
	}

	public function match(string $method, string $path): RouteResult
	{
		$dispatcher = new GroupCountBased($this->collector()->getData());
		$result = $dispatcher->dispatch(strtoupper($method), $this->normalizePath($path));

		return match ($result[0]) {
			Dispatcher::FOUND => RouteResult::fromRoute($result[1], $result[2]),
			Dispatcher::METHOD_NOT_ALLOWED => RouteResult::fromRouteFailure($result[1]),
			default => RouteResult::fromRouteFailure(Route::HTTP_METHOD_ANY),
		};
	}

	private function collector(): RouteCollector
	{
		$collector = new RouteCollector(new RouteParser(), new RouteGenerator());

		foreach ($this->routes as $route) {
			$path = $this->normalizePath($route['path']);
			$collector->addRoute(
				$route['methods'],
				$path,
				new Route(
					$path,
					$route['action'],
					$route['methods'],
					$route['name'],
					$route['options'] ?? [],
				)
			);
		}

		return $collector;
	}

	private function normalizePath(string $path): string
	{
		$path = trim($path, '/');

		return $path === '' ? '/' : '/' . $path;
	}
}
