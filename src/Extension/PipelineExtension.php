<?php

namespace ON\Extension;

use DI\Attribute\Inject;
use Exception;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\MiddlewarePipeInterface;
use League\Event\ListenerPriority;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use ON\Application;
use ON\Container\MiddlewareFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ON\Event\EventSubscriberInterface;
use ON\Event\NamedEvent;
use ON\RequestStack;

use function Laminas\Stratigility\path;

class PipelineExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    protected RequestHandlerRunnerInterface $runner;

    protected RequestStack $requestStack;
    
    protected MiddlewarePipeInterface $pipeline;
    public MiddlewareFactory $factory;

    public function __construct(
        protected Application $app,

    ) {

    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app);
        $app->registerExtension('pipeline', $extension);
        return $extension;
    }

    public function setup(int $counter): bool
    {
        if ($this->app->isExtensionReady('container')) {

            $this->app->requestStack = $this->requestStack = new RequestStack();
            
            $container = $this->app->getContainer();
            
            $this->pipeline = $container->get(MiddlewarePipeInterface::class);
            $this->factory = $container->get(MiddlewareFactory::class);

            $config = $container->get('config');
            
            $this->app->registerMethod("pipe", [$this, "pipe"]);
            $this->app->registerMethod("runAction", [$this, "runAction"]);
            $this->app->registerMethod("process", [$this, "process"]);
            $this->app->registerMethod("handle", [$this, "handle"]);
            $this->app->registerMethod("run", [$this, "run"]);

            $this->runner = $container->get(RequestHandlerRunner::class);
            $this->loadPipeline($config->get('app.pipeline_file'));
            return true;
        }
        return false;
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

    public function runAction ($request, $response = null) {
        $response = $response ?: new Response();
        return $this->handle($request);
    }

    /**
     * Run the application.
     *
     * Proxies to the RequestHandlerRunner::run() method.
     */
    public function run(): void
    {
        // core.run event
        if ($dispatcher = $this->app->ext('events')) {
            /** @var \ON\Extension\EventsExtension $dispatcher */
            $dispatcher->dispatch(new NamedEvent("core.run"));
        }

        $this->runner->run();

        // core.end event
        if ($dispatcher = $this->app->ext('events')) {
            /** @var \ON\Extension\EventsExtension $dispatcher */
            $dispatcher->dispatch(new NamedEvent("core.end"));
        }
    }
}
