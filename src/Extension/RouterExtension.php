<?php

namespace ON\Extension;

use Exception;
use Mezzio\Router\DuplicateRouteDetector;
use Mezzio\Router\Route;
use ON\Application;
use ON\Router\RouterInterface;

class RouterExtension implements ExtensionInterface
{
 
    private ?DuplicateRouteDetector $duplicateRouteDetector = null;
    private bool $detectDuplicates = true;
    
    public function __construct(
        protected Application $app,
        protected RouterInterface $router
    ) {

    }

    public static function install(Application $app): mixed {
        $container = Application::getContainer();
        $extension = $container->get(self::class);

        if (!$app->hasExtension(PipelineExtension::class)) {
            throw new Exception("The RouterExtension needs the PipelineExtension to work property.");
        }
        
        $app->registerMethod("get", [$extension, 'get']);
        $app->registerMethod("put", [$extension, 'put']);
        $app->registerMethod("patch", [$extension, 'patch']);
        $app->registerMethod("any", [$extension, 'any']);
        $app->registerMethod("post", [$extension, 'post']);

        $extension->loadRoutes($container->get('config')->get('app.routes_file'));
        
        return $extension;
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
}
