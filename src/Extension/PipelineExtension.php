<?php

namespace ON\Extension;

use Exception;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Stratigility\MiddlewarePipeInterface;
use League\Event\HasEventName;
use League\Event\ListenerPriority;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use ON\Application;
use ON\Container\MiddlewareFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function Laminas\Stratigility\path;

class PipelineExtension implements ExtensionInterface
{
 
    

    public function __construct(
        protected Application $app,
        protected MiddlewarePipeInterface $pipeline,
        public MiddlewareFactory $factory
    ) {

    }

    public static function install(Application $app): mixed {
        $container = Application::getContainer();
        $extension = $container->get(self::class);
        $app->registerExtension(self::class, $extension);
        $extension->loadPipeline($container->get('config')->get('app.pipeline_file'));
        return $extension;
    }

    protected function loadPipeline(string $file) {
        (require_once $file)($this->app);
    }

    /**
     * Pipe middleware to the pipeline.
     *
     * If two arguments are present, they are passed to pipe(), after first
     * passing the second argument to the factory's prepare() method.
     *
     * If only one argument is presented, it is passed to the factory prepare()
     * method.
     *
     * The resulting middleware, in both cases, is piped to the pipeline.
     *
     * @param string|array|callable|MiddlewareInterface|RequestHandlerInterface $middlewareOrPath
     *     Either the middleware to pipe, or the path to segregate the $middleware
     *     by, via a PathMiddlewareDecorator.
     * @param null|string|array|callable|MiddlewareInterface|RequestHandlerInterface $middleware
     *     If present, middleware or request handler to segregate by the path
     *     specified in $middlewareOrPath.
     * @psalm-param string|MiddlewareParam $middlewareOrPath
     * @psalm-param null|MiddlewareParam $middleware
     */
    public function pipe($middlewareOrPath, $middleware = null): void
    {
        $middleware = $middleware ?? $middlewareOrPath;
        $path       = $middleware === $middlewareOrPath ? '/' : $middlewareOrPath;

        $middleware = $path !== '/'
            ? path($path, $this->factory->prepare($middleware))
            : $this->factory->prepare($middleware);

        $this->pipeline->pipe($middleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->pipeline->process($request, $handler);
    }

    public function prepareRequest($path, $controller, $methods, $route_name) {
        $request = ServerRequestFactory::fromGlobals();

        if (is_string($controller)) { //middleware
            $controller = $this->factory->prepare($controller);
        }

        $route = new Route($path, $controller, $methods, $route_name);
        $route_result = RouteResult::fromRoute($route);

        $request = $request->withAttribute(RouteResult::class, $route_result);

        return $request;
    }
}
