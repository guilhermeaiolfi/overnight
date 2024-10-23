<?php

namespace ON\Extension;

use Exception;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\MiddlewarePipeInterface;

use ON\Router\Route;
use ON\Router\RouteResult;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\ContainerConfig;
use Psr\Http\Server\RequestHandlerInterface;
use ON\Event\NamedEvent;
use ON\RequestStack;

use ON\Response\ServerRequestErrorResponseGenerator;
use ON\MiddlewareContainer;

use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\Stratigility\Middleware\ErrorHandler;
use ON\Action;
use ON\Cache\CacheInterface;
use ON\MiddlewareFactoryInterface;
use ON\Handler\NotFoundHandler;
use ON\Middleware\ErrorResponseGenerator;
use ON\MiddlewareFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;

use function Laminas\Stratigility\path;

class PipelineExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    protected RequestHandlerRunnerInterface $runner;

    protected array $pendingTasks = [ "container:define", "pipeline:load" ];

    protected RequestStack $requestStack;
    
    protected MiddlewarePipeInterface $pipeline;
    public MiddlewareFactory $factory;

    protected array $controllerCache = [];

    public function __construct(
        protected Application $app,

    ) {
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app);
        $app->registerExtension('pipeline', $extension);
        return $extension;
    }

    public function getController($class_name) {
        return $this->controllerCache[$class_name];
    }

    public function setup(int $counter): bool
    {
        if ($this->hasPendingTask("container:define")) {
            $config = $this->app->ext('config');

            if (!isset($config)) {
                throw new Exception("Pipeline Extension needs the config extension");
            }
            $containerConfig = $config->get(ContainerConfig::class);
            $containerConfig->mergeConfigArray([
                "definitions" => [
                    "aliases" => [
                        MiddlewarePipeInterface::class                      => \Laminas\Stratigility\MiddlewarePipe::class,
                        MiddlewareFactoryInterface::class                   => \ON\MiddlewareFactory::class,
                        ResponseFactoryInterface::class                     => \Laminas\Diactoros\ResponseFactory::class
                    ],
                    "factories" => [
                        EmitterInterface::class                             => \ON\Container\EmitterFactory::class,
                        ErrorHandler::class                                 => \ON\Container\ErrorHandlerFactory::class,
                        MiddlewareContainer::class                          => \ON\Container\MiddlewareContainerFactory::class,
                        
                        // Change the following in development to the WhoopsErrorResponseGeneratorFactory:
                        //ErrorResponseGenerator::class                       => \ON\Container\ErrorResponseGeneratorFactory::class,
                        ErrorResponseGenerator::class                       => \ON\Container\WhoopsErrorResponseGeneratorFactory::class,
                        'ON\Whoops'                                         => \ON\Container\WhoopsFactory::class,
                        'ON\WhoopsPageHandler'                              => \ON\Container\WhoopsPageHandlerFactory::class,

                        ResponseInterface::class                            => \ON\Container\ResponseFactoryFactory::class,
                        RequestHandlerRunner::class                         => \ON\Container\RequestHandlerRunnerFactory::class,
                        
                        ServerRequestErrorResponseGenerator::class          => \ON\Container\ServerRequestErrorResponseGeneratorFactory::class,
                        ServerRequestInterface::class                       => \ON\Container\ServerRequestFactoryFactory::class,
                        StreamInterface::class                              => \ON\Container\StreamFactoryFactory::class,
                        NotFoundHandler::class                              => \ON\Container\NotFoundHandlerFactory::class,

                    ],
                ]
            ]);
            $this->removePendingTask('container:define');
        } else if ($this->hasPendingTask("pipeline:load") && $this->app->isExtensionReady('container')) {

            $this->app->requestStack = $this->requestStack = new RequestStack();
            
            $container = $this->app->getContainer();
            
            $this->pipeline = $container->get(MiddlewarePipeInterface::class);
            $this->factory = $container->get(MiddlewareFactory::class);

            $config = $container->get(AppConfig::class);
            
            $this->app->registerMethod("pipe", [$this, "pipe"]);
            $this->app->registerMethod("runAction", [$this, "runAction"]);
            $this->app->registerMethod("process", [$this, "process"]);
            $this->app->registerMethod("handle", [$this, "handle"]);
            $this->app->registerMethod("run", [$this, "run"]);

            $cache = $container->get(CacheInterface::class);
            $runner = $cache->resolve("runner", function() use($container) {
                return $container->get(RequestHandlerRunner::class);
            });
            //dd($runner);
            $this->runner = $runner;
            $this->loadPipeline($config->get('app.pipeline_file', 'config/pipeline.php'));
            $this->removePendingTask('pipeline:load');
            
        }

        if (empty($this->getPendingTasks())) {
            return true;
        }

        return false;
    }

    public function prepareMiddleware($middleware): MiddlewareInterface
    {
        return $this->factory->prepare($middleware);
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
            ? path($path, $this->prepareMiddleware($middleware))
            : $this->prepareMiddleware($middleware);

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

    public function prepareRequest($path, $controller, $methods, $route_name): RequestInterface {
        $route = new Route($path, $controller, $methods, $route_name);
        $route_result = RouteResult::fromRoute($route);
        return $this->prepareRequestFromRouteResult($route_result);
    }

    public function prepareRequestFromRouteResult($result, $request = null): RequestInterface
    {
        if (!$request) {
            $request = ServerRequestFactory::fromGlobals();
        }

        $original_request = $request;

        // Inject the actual route result, as well as individual matched parameters.
        $request = $request->withAttribute(RouteResult::class, $result);

        foreach ($result->getMatchedParams() as $param => $value) {
            $request = $request->withAttribute($param, $value);
        }


        $options = $result->getMatchedRoute()->getOptions();
        if (!empty($options) && !empty($options["callbacks"]) && is_array($options["callbacks"])) {
            foreach ($options["callbacks"] as $callback) {
                $callback = $this->app->getContainer()->get($callback);
                $result = $callback->onMatched($result);
            }
        }

        // We need to update the params again, since it may have entered callbacks that changed it
        $request = $request->withAttribute(RouteResult::class, $result);

        foreach ($result->getMatchedParams() as $param => $value) {
            $request = $request->withAttribute($param, $value);
        }


        $middleware = $result->getMatchedRoute()->getMiddleware();
        
        if (is_string($middleware)) {
            $middleware = $this->app->ext('pipeline')->prepareMiddleware($middleware);
            $result->getMatchedRoute()->setMiddleware($middleware);
        }
        
        $this->requestStack->update($original_request, $request);
        return $request;
    }

    public function runAction ($request, $response = null) {
        //$response = $response ?: new Response();
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
