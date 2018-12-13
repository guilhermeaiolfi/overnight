<?php
namespace ON;

use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Zend\Expressive\Handler\NotFoundHandler;

use Zend\Diactoros\Response;
use Zend\Diactoros\Response\EmitterInterface;
use Zend\Diactoros\Response\SapiEmitter;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Stratigility\MiddlewarePipe;
use Zend\Expressive\Router\Route;
use Zend\Expressive\Router;
use Zend\Expressive\RouterInterface;
use Zend\Expressive\UnexpectedValueException;
use Zend\Expressive\Emitter\EmitterStack;
use Zend\Expressive\InvalidArgumentException;
use ON\Middleware\RouteMiddleware;
use ON\Router\StatefulRouterInterface;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Expressive\Router\RouteCollector;
use Zend\HttpHandlerRunner\RequestHandlerRunner;
use Zend\Stratigility\MiddlewarePipeInterface;

use function Zend\Stratigility\path;
use Zend\Expressive\MiddlewareFactory;

use ON\Router\ActionMiddlewareDecorator;

function actionMiddleware ($middleware) {
  return new ActionMiddlewareDecorator($middleware);
}

class Application extends \Zend\Expressive\Application
{
    /**
     * @var MiddlewareFactory
     */
    protected $factory;
    /**
     * @var MiddlewarePipeInterface
     */
    protected $pipeline;
    /**
     * @var RouteCollector
     */
    protected $routes;
    /**
     * @var RequestHandlerRunner
     */
    protected $runner;

    /**
     * Constructor
     *
     * Calls on the parent constructor, and then uses the provided arguments
     * to set internal properties.
     *
     * @param Router\RouterInterface $router
     * @param null|ContainerInterface $container IoC container from which to pull services, if any.
     * @param null|RequestHandlerInterface $handler Default request handler
     *     to use when $out is not provided on invocation / run() is invoked.
     * @param null|EmitterInterface $emitter Emitter to use when `run()` is
     *     invoked.
     */
    public function __construct(
        MiddlewareFactory $factory,
        MiddlewarePipeInterface $pipeline,
        RouteCollector $routes,
        RequestHandlerRunner $runner,

        StatefulRouterInterface $router
    ) {
        parent::__construct($factory, $pipeline, $routes, $runner);
        $this->router          = $router;
        $this->runner          = $runner;
        $this->routes          = $routes;
        $this->pipeline        = $pipeline;
        $this->factory        = $factory;
    }

     /**
     * Add a route for the route middleware to match.
     *
     * Accepts either a Router\Route instance, or a combination of a path and
     * middleware, and optionally the HTTP methods allowed.
     *
     * On first invocation, pipes the route middleware to the middleware
     * pipeline.
     *
     * @param string|Router\Route $path
     * @param callable|string|array $middleware Middleware (or middleware service name) to associate with route.
     * @param null|array $methods HTTP method to accept; null indicates any.
     * @param null|string $name The name of the route.
     * @return Router\Route
     * @throws Exception\InvalidArgumentException if $path is not a Router\Route AND middleware is null.
     */
    public function route(string $path, $middleware, ?array $methods = null, ?string $name = null): \Zend\Expressive\Router\Route
    {
        $middleware = actionMiddleware($middleware);
        return $this->routes->route(
            $path,
            $this->factory->prepare($middleware),
            $methods,
            $name
        );
    }

    /**
     * Retrieve all directly registered routes with the application.
     *
     * @return Router\Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Run the action
     *
     * If no request or response are provided, the method will use
     * ServerRequestFactory::fromGlobals to create a request instance, and
     * instantiate a default response instance.
     *
     * It retrieves the default delegate using getDefaultRequestHandler(), and
     * uses that to process itself.
     *
     * Once it has processed itself, it emits the returned response using the
     * composed emitter.
     *
     * @param null|ServerRequestInterface $request
     * @param null|ResponseInterface $response
     * @return void
     */
    public function runAction(ServerRequestInterface $request = null, ResponseInterface $response = null)
    {
        $response = $response ?: new Response();
        $request  = $request->withAttribute('originalResponse', $response);

        return $this->handle($request);
    }
}