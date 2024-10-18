<?php

namespace ON\Extension;

use Exception;
use ON\Router\DuplicateRouteDetector;
use ON\Router\Route;
use ON\Application;
use ON\Config\ContainerConfig;
use ON\Event\EventSubscriberInterface;
use ON\Router\RouterInterface;


use ON\Router\RouteCollector;
use ON\Router\RouteCollectorInterface;
use ON\Middleware\RouteMiddleware;

use ON\Router\Router;

class RouterExtension extends AbstractExtension implements EventSubscriberInterface
{
    protected int $type = self::TYPE_EXTENSION;
    private ?DuplicateRouteDetector $duplicateRouteDetector = null;
    private bool $detectDuplicates = true;

    protected RouterInterface $router;
    
    protected array $pendingTasks = [ 'container:define', 'router:load', 'events:load' ];
    public function __construct(
        protected Application $app
    ) {
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $class = self::class;
        $extension = new $class($app);

        if (!$app->hasExtension(PipelineExtension::class)) {
            throw new Exception("The RouterExtension needs the PipelineExtension to work property.");
        }
        
        $app->registerMethod("get", [$extension, 'get']);
        $app->registerMethod("put", [$extension, 'put']);
        $app->registerMethod("patch", [$extension, 'patch']);
        $app->registerMethod("any", [$extension, 'any']);
        $app->registerMethod("post", [$extension, 'post']);
        $app->registerMethod("route", [$extension, 'route']);

        $app->registerExtension('router', $extension);
        
        return $extension;
    }

    public function setup(int $counter): bool
    {
        if (!$this->app->hasExtension('events'))
        {
            throw new Exception("RouterExtension needs the EventsExtension to work properly.");
            return false;
        }

        if ($this->hasPendingTask("container:define")) {
            $config = $this->app->ext('config');

            if (!isset($config)) {
                throw new Exception("Router Extension needs the config extension");
            }
            $containerConfig = $config->get(ContainerConfig::class);
            $containerConfig->mergeRecursiveDistinct([
                "definitions" => [
                    "aliases" => [
                        RouteCollectorInterface::class                      => \ON\Router\RouteCollector::class,
                        RouterInterface::class                              => \ON\Router\Router::class,
                        
                    ],
                    "factories" => [
                        Router::class                                       => \ON\Router\RouterFactory::class,
                        RouteMiddleware::class                              => \ON\Container\RouteMiddlewareFactory::class,
                        RouterInterface::class                              => \ON\Router\RouterFactory::class,
                        RouteCollector::class                               => \ON\Router\RouteCollectorFactory::class,
                    ]
                ]
            ]);
            $this->removePendingTask('container:define');
    
        }
        if ($this->app->isExtensionReady('container') && $this->hasPendingTask('router:load')) {
            $container = $this->app->getContainer();
            $this->router = $container->get(RouterInterface::class);
            $this->loadRoutes($container->get('config')->get('app.routes_file', 'config/routes.php'));
            $this->removePendingTask('router:load');
        }
        if ($this->app->isExtensionReady('events') && $this->hasPendingTask('events:load')) {
            /** @var EventsExtension $dispatcher */
            $dispatcher = $this->app->ext('events');
            $dispatcher->loadEventSubscriber($this);
            $this->removePendingTask('events:load');
        }

        if (empty($this->getPendingTasks())) {
            return true;
        }
        return false;
    }

    protected function loadRoutes(string $file) {
        (require_once $file)($this->app);
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
        $middleware = $this->app->getExtension(PipelineExtension::class)->factory->prepare($middleware);
        $methods = $methods ?? Route::HTTP_METHOD_ANY;
        
        $route   = new Route($path, $middleware, $methods, $name);
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

    public function onRun($event) {
        return;
    }

    public static function getSubscribedEvents() {
        return [
            'core.run' => 'onRun'
        ];
    }
}
