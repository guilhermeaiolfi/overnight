<?php

declare(strict_types=1);

namespace ON\Router;

use ON\Application;
use ON\Config\AppConfig;
use ON\Container\ContainerConfig;
use ON\Discovery\AttributesDiscoverer;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Router\Attribute\RouteAttributeProcessor;
use ON\Router\Container\RouteMiddlewareFactory;
use ON\Router\Middleware\RouteMiddleware;
use ON\Router\Container\RouterFactory;

class RouterExtension extends AbstractExtension
{
	public const NAMESPACE = "core.extensions.router";
	protected int $type = self::TYPE_EXTENSION;

	protected RouterInterface $router;

	protected RouterConfig $config;

	protected array $pendingTasks = [ ];

	public function __construct(
		protected Application $app,
		protected ?array $options = []
	) {
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);

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

	public function boot(): void
	{
		$this->when('installed', [$this, 'setup']);
		$this->app->ext('container')->when('setup', [$this, 'onContainerSetup']);
		$this->app->ext('container')->when('ready', [$this, 'onContainerReady']);
		$this->app->ext('config')->when('setup', [$this, 'onConfigSetup']);
		//$this->app->ext('pipeline')->when('ready', [$this, 'onPipelineReady']);
	}

	public function setup(): void
	{
		$this->dispatchStateChange('setup');

		$this->app->registerMethod("get", [$this, 'get']);
		$this->app->registerMethod("put", [$this, 'put']);
		$this->app->registerMethod("patch", [$this, 'patch']);
		$this->app->registerMethod("any", [$this, 'any']);
		$this->app->registerMethod("post", [$this, 'post']);
		$this->app->registerMethod("route", [$this, 'route']);

		$this->app->pipe("/", RouteMiddleware::class, 100);
	}

	public function onConfigSetup(): void
	{
		$appCfg = $this->app->config->get(AppConfig::class);
		$appCfg->set("discovery.discoverers." . AttributesDiscoverer::class . ".processors." . RouteAttributeProcessor::class, []);
	}

	public function onContainerSetup(): void
	{
		$containerConfig = $this->app->config->get(ContainerConfig::class);
		$containerConfig->addAlias(RouterInterface::class, Router::class);
		$containerConfig->addFactories([
			Router::class => RouterFactory::class,
			RouteMiddleware::class => RouteMiddlewareFactory::class,
			RouterInterface::class => RouterFactory::class,
		]);
	}

	public function onContainerReady(): void
	{
		$container = $this->app->container;
		$this->router = $container->get(RouterInterface::class);
		$this->app->router = $this;
		$this->config = $container->get(RouterConfig::class);

		$this->dispatchStateChange('ready');
	}

	protected function loadRoutes(string $file)
	{
		(require_once $file)($this->app);
	}

	public function addRoute(Route $route): self
	{
		$this->config->addRoute($route);

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

		$this->addRoute($route);

		return $route;
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
}
