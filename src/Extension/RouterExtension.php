<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;
use ON\Config\RouterConfig;
use ON\Container\RouteMiddlewareFactory;
use ON\Event\EventSubscriberInterface;
use ON\Middleware\RouteMiddleware;
use ON\Router\DuplicateRouteDetector;
use ON\Router\Route;
use ON\Router\Router;
use ON\Router\RouterFactory;
use ON\Router\RouterInterface;

class RouterExtension extends AbstractExtension implements EventSubscriberInterface
{
	public const NAMESPACE = "core.extensions.router";
	protected int $type = self::TYPE_EXTENSION;
	private ?DuplicateRouteDetector $duplicateRouteDetector = null;
	private bool $detectDuplicates = true;

	protected RouterInterface $router;

	protected array $pendingTasks = [ 'container:define', 'router:load' ];

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		$class = self::class;
		$extension = new $class($app);

		$app->registerMethod("get", [$extension, 'get']);
		$app->registerMethod("put", [$extension, 'put']);
		$app->registerMethod("patch", [$extension, 'patch']);
		$app->registerMethod("any", [$extension, 'any']);
		$app->registerMethod("post", [$extension, 'post']);
		$app->registerMethod("route", [$extension, 'route']);

		$app->registerExtension('router', $extension);

		return $extension;
	}

	public function requires(): array
	{
		return [
			'events',
			'container',
		];
	}

	public function ready(): void
	{
		$this->app->events->dispatch('core.extensions.router.ready', $this);
	}

	public function setup(int $counter): bool
	{
		if (! $this->hasPendingTasks()) {
			return true;
		}

		return false;
	}

	protected function loadRoutesFromConfig($cfg)
	{
		$routes = $cfg->get('routes');
		if (is_array($routes)) {
			foreach ($routes as $route) {
				if (is_array($route)) {
					$this->route(...$route);
				} else {
					$this->router->addRoute($route);
				}
			}
		}
		$cfg->done();
	}

	protected function loadRoutes(string $file)
	{
		(require_once $file)($this->app);
	}

	public function addRoute(Route $route): self
	{
		$this->detectDuplicateRoute($route);
		$this->router->addRoute($route);

		return $this;
	}

	/**
	 * Add a route for the route middleware to match.
	 *
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|list<string> $methods HTTP method to accept; null indicates any.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function route(string $path, $middleware, ?array $methods = null, ?string $name = null): Route
	{
		$methods = $methods ?? Route::HTTP_METHOD_ANY;

		$route = new Route($path, $middleware, $methods, $name);
		$this->detectDuplicateRoute($route);

		$this->router->addRoute($route);

		return $route;
	}

	private function detectDuplicateRoute(Route $route): void
	{
		if ($this->detectDuplicates && ! $this->duplicateRouteDetector) {
			$this->duplicateRouteDetector = new DuplicateRouteDetector();
		}

		if ($this->duplicateRouteDetector) {
			$this->duplicateRouteDetector->detectDuplicate($route);

			return;
		}
	}

	/**
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function get(string $path, $middleware, ?string $name = null): Route
	{
		return $this->route($path, $middleware, ['GET'], $name);
	}

	/**
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function post(string $path, $middleware, $name = null): Route
	{
		return $this->route($path, $middleware, ['POST'], $name);
	}

	/**
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function put(string $path, $middleware, ?string $name = null): Route
	{
		return $this->route($path, $middleware, ['PUT'], $name);
	}

	/**
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function patch(string $path, $middleware, ?string $name = null): Route
	{
		return $this->route($path, $middleware, ['PATCH'], $name);
	}

	/**
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function delete(string $path, $middleware, ?string $name = null): Route
	{
		return $this->route($path, $middleware, ['DELETE'], $name);
	}

	/**
	 * @param non-empty-string $path
	 * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
	 *     Middleware or request handler (or service name resolving to one of
	 *     those types) to associate with route.
	 * @param null|non-empty-string $name The name of the route.
	 */
	public function any(string $path, $middleware, ?string $name = null): Route
	{
		return $this->route($path, $middleware, null, $name);
	}

	public function onContainerConfig($event): void
	{
		$containerConfig = $event->getSubject();
		$containerConfig->addAlias(RouterInterface::class, Router::class);
		$containerConfig->addFactories([
			Router::class => RouterFactory::class,
			RouteMiddleware::class => RouteMiddlewareFactory::class,
			RouterInterface::class => RouterFactory::class,
		]);
		$this->removePendingTask('container:define');
	}

	public function onContainerReady(): void
	{
		$container = $this->app->container;
		$this->router = $container->get(RouterInterface::class);
		$this->app->router = $this;
		$routerCfg = $container->get(RouterConfig::class);
		$this->loadRoutesFromConfig($routerCfg);
		$this->removePendingTask('router:load');
	}

	public function onPipelineReady(): void
	{
		$this->app->pipe("/", RouteMiddleware::class, 100);
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.extensions.container.config' => 'onContainerConfig',
			'core.extensions.container.ready' => 'onContainerReady',
			'core.extensions.pipeline.ready' => 'onPipelineReady',
		];
	}
}
