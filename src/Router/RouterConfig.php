<?php

declare(strict_types=1);

namespace ON\Router;

use On\Config\Config;

class RouterConfig extends Config
{
	protected array $routesToInject = [];
	protected array $routesInjected = [];
	protected string $currentGroupPrefix = '';

	public static function getDefaults(): array
	{
		return [
			"cache_enabled" => ! $_ENV["APP_DEBUG"], // true|false
			"cache_file" => 'var/cache/router.php.cache', // optional
			"baseHref" => null,
		];
	}

	public function addRoute(mixed $path_or_obj, string $action = null, ?array $methods = ["GET"], $route_name = null)
	{
		$route = $path_or_obj;

		if ($path_or_obj instanceof Route) {
			$route->prependPath($this->currentGroupPrefix);
		} else {
			$route = new Route(
				$this->currentGroupPrefix . $path_or_obj,
				$action,
				$methods,
				$route_name,
			);
		}
		$this->routesToInject[] = $route;
	}

	/**
	 * Create a route group with a common prefix.
	 *
	 * All routes created in the passed callback will have the given group prefix prepended.
	 *
	 * @param string $prefix
	 * @param callable $callback
	 */
	public function addGroup($prefix, callable $callback)
	{
		if ($this->currentGroupPrefix == '') {
			// first prefix, let's ensure we have the first /
			$prefix = "/" . ltrim($prefix, "/");
		} else {
			// otherwise, lets remove the first /, because the prefix already ends in /
			$prefix = ltrim($prefix, "/");
		}

		// let's add / to the end
		$prefix = rtrim($prefix, "/") . "/";

		$previousGroupPrefix = $this->currentGroupPrefix;
		$this->currentGroupPrefix = $previousGroupPrefix . $prefix;
		$callback($this);
		$this->currentGroupPrefix = $previousGroupPrefix;
	}

	public function isDirty(): bool
	{
		return ! empty($this->routesToInject);
	}

	public function getRoutesToInject(): array
	{
		return $this->routesToInject;
	}

	public function pullAndInject(): ?Route
	{
		$route = array_shift($this->routesToInject);

		if (isset($route)) {
			$this->registerRoute($route);
		}

		return $route;
	}

	public function registerRoute(Route $route): void
	{
		$this->routesInjected[$route->getName()] = $route;
	}

	public function getRoutes(): array
	{
		return $this->routesInjected;
	}

	public function getRouteByName(string $name): ?Route
	{
		return $this->routesInjected[$name] ?? null;
	}
}
