<?php

declare(strict_types=1);

namespace ON\Extension;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Laminas\Stratigility\MiddlewarePipe;
use Laminas\Stratigility\MiddlewarePipeInterface;
use function Laminas\Stratigility\path;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\ContainerConfig;
use ON\Container\EmitterFactory;
use ON\Container\ErrorHandlerFactory;
use ON\Container\MiddlewareContainerFactory;
use ON\Container\NotFoundHandlerFactory;
use ON\Container\RequestHandlerRunnerFactory;
use ON\Container\ResponseFactoryFactory;
use ON\Container\ServerRequestErrorResponseGeneratorFactory;
use ON\Container\ServerRequestFactoryFactory;
use ON\Container\StreamFactoryFactory;
use ON\Container\WhoopsErrorResponseGeneratorFactory;
use ON\Container\WhoopsFactory;
use ON\Container\WhoopsPageHandlerFactory;
use ON\Event\EventSubscriberInterface;
use ON\Event\NamedEvent;
use ON\Handler\NotFoundHandler;
use ON\Middleware\ErrorResponseGenerator;
use ON\MiddlewareContainer;
use ON\MiddlewareFactory;
use ON\MiddlewareFactoryInterface;
use ON\RequestStack;
use ON\Response\ServerRequestErrorResponseGenerator;
use ON\Router\Route;
use ON\Router\RouteResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PipelineExtension extends AbstractExtension implements EventSubscriberInterface
{
	public const NAMESPACE = "core.extensions.pipeline";
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

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app);
		$app->registerExtension('pipeline', $extension);
		$app->pipeline = $extension;

		return $extension;
	}

	public function getController($class_name)
	{
		return $this->controllerCache[$class_name];
	}

	public function requires(): array
	{
		return [
			'container',
			'config',
		];
	}

	protected function setupPipeline(): void {
		$this->app->requestStack = $this->requestStack = new RequestStack();

		$container = $this->app->container;

		$this->pipeline = $container->get(MiddlewarePipeInterface::class);
		$this->factory = $container->get(MiddlewareFactory::class);

		$this->app->registerMethod("pipe", [$this, "pipe"]);
		$this->app->registerMethod("runAction", [$this, "runAction"]);
		$this->app->registerMethod("process", [$this, "process"]);
		$this->app->registerMethod("handle", [$this, "handle"]);

		if (!$this->app->isCli()) {
			$this->app->registerMethod("run", [$this, "run"]);
		}

		$this->runner = $container->get(RequestHandlerRunner::class);
	}

	public function prepareMiddleware($middleware): MiddlewareInterface
	{
		return $this->factory->prepare($middleware);
	}

	protected function loadPipeline(string $file)
	{
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
		$path = $middleware === $middlewareOrPath ? '/' : $middlewareOrPath;

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

	public function prepareRequestFromRoute(Route $route): RequestInterface
	{
		$route_result = RouteResult::fromRoute($route);

		return $this->prepareRequestFromRouteResult($route_result);
	}

	public function prepareRequest($path, $controller, $methods, $route_name): RequestInterface
	{
		$route = new Route($path, $controller, $methods, $route_name);
		$route_result = RouteResult::fromRoute($route);

		return $this->prepareRequestFromRouteResult($route_result);
	}

	public function prepareRequestFromRouteResult($result, $request = null): RequestInterface
	{
		if (! $request) {
			$request = ServerRequestFactory::fromGlobals();
		}

		$original_request = $request;

		// Inject the actual route result, as well as individual matched parameters.
		$request = $request->withAttribute(RouteResult::class, $result);

		foreach ($result->getMatchedParams() as $param => $value) {
			$request = $request->withAttribute($param, $value);
		}


		$options = $result->getMatchedRoute()->getOptions();
		if (! empty($options) && ! empty($options["callbacks"]) && is_array($options["callbacks"])) {
			foreach ($options["callbacks"] as $callback) {
				$callback = $this->app->container->get($callback);
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
			$middleware = $this->prepareMiddleware($middleware);
			$result->getMatchedRoute()->setMiddleware($middleware);
		}

		$this->requestStack->update($original_request, $request);

		return $request;
	}

	public function runAction($request, $response = null)
	{
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
			/** @var EventsExtension $dispatcher */
			$dispatcher->dispatch(new NamedEvent("core.run"));
		}

		$this->runner->run();

		// core.end event
		if ($dispatcher = $this->app->ext('events')) {
			/** @var EventsExtension $dispatcher */
			$dispatcher->dispatch(new NamedEvent("core.end"));
		}
	}

	public function onConfigSetup(): void
	{
		$config = $this->app->config;

		$containerConfig = $config->get(ContainerConfig::class);
		$containerConfig->mergeConfigArray([
			"definitions" => [
				"aliases" => [
					MiddlewarePipeInterface::class => MiddlewarePipe::class,
					MiddlewareFactoryInterface::class => MiddlewareFactory::class,
					ResponseFactoryInterface::class => ResponseFactory::class,
				],
				"factories" => [
					EmitterInterface::class => EmitterFactory::class,
					ErrorHandler::class => ErrorHandlerFactory::class,
					MiddlewareContainer::class => MiddlewareContainerFactory::class,

					// Change the following in development to the WhoopsErrorResponseGeneratorFactory:
					ErrorResponseGenerator::class                       => \ON\Container\ErrorResponseGeneratorFactory::class,
					//ErrorResponseGenerator::class => WhoopsErrorResponseGeneratorFactory::class,
					'ON\Whoops' => WhoopsFactory::class,
					'ON\WhoopsPageHandler' => WhoopsPageHandlerFactory::class,

					ResponseInterface::class => ResponseFactoryFactory::class,
					RequestHandlerRunner::class => RequestHandlerRunnerFactory::class,

					ServerRequestErrorResponseGenerator::class => ServerRequestErrorResponseGeneratorFactory::class,
					ServerRequestInterface::class => ServerRequestFactoryFactory::class,
					StreamInterface::class => StreamFactoryFactory::class,
					NotFoundHandler::class => NotFoundHandlerFactory::class,

				],
			],
		]);
		$this->removePendingTask('container:define');
	}

	public function onContainerReady(): void
	{
		$this->setupPipeline();
		
		
		$appCfg = $this->app->config->get(AppConfig::class);
		$this->loadPipeline($appCfg->get('app.pipeline_file', 'config/pipeline.php'));
		$this->removePendingTask('pipeline:load');
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.extensions.config.setup' => 'onConfigSetup',
			'core.extensions.container.ready' => 'onContainerReady'
		];
	}
	
}
