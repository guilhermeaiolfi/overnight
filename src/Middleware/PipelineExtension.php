<?php

declare(strict_types=1);

namespace ON\Middleware;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Laminas\Stratigility\MiddlewarePipeInterface;
use function Laminas\Stratigility\path;
use Mezzio\Helper\BodyParams\BodyParamsMiddleware;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerConfig;
use ON\Container\EmitterFactory;
use ON\Container\ErrorHandlerFactory;
use ON\Container\ErrorResponseGeneratorFactory;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Container\MiddlewareContainerFactory;
use ON\Container\NotFoundHandlerFactory;
use ON\Container\RequestHandlerRunnerFactory;
use ON\Container\ResponseFactoryFactory;
use ON\Container\ServerRequestErrorResponseGeneratorFactory;
use ON\Container\ServerRequestFactoryFactory;
use ON\Container\StreamFactoryFactory;
use ON\Container\WhoopsFactory;
use ON\Container\WhoopsPageHandlerFactory;
use ON\Event\NamedEvent;
use ON\Extension\AbstractExtension;
use ON\Handler\NotFoundHandler;
use ON\Http\RequestContext;
use ON\Init\Init;
use ON\Init\InitContext;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\MiddlewareContainer;
use ON\MiddlewareFactory;
use ON\MiddlewareFactoryInterface;
use ON\RequestStack;
use ON\RequestStackInterface;
use ON\Response\ServerRequestErrorResponseGenerator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Owns the PSR-15 middleware pipeline lifecycle for the application.
 *
 * This extension is intentionally transport-focused: it wires the pipeline,
 * queues middleware registration until the container is ready, and forwards
 * requests by rewriting the request and re-entering the pipeline.
 */
class PipelineExtension extends AbstractExtension
{
	public const ID = 'pipeline';

	public const NAMESPACE = 'core.extensions.pipeline';
	protected int $type = self::TYPE_EXTENSION;
	protected RequestHandlerRunnerInterface $runner;
	protected RequestStack $requestStack;
	protected MiddlewarePipeInterface $pipeline;
	public MiddlewareFactory $factory;
	protected ContainerInterface $container;
	protected array $q = [];
	/** @var array<int, array{priority:int, preparer:RequestPreparerInterface}> */
	protected array $requestPreparers = [];

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$this->app->registerMethod('pipe', [$this, 'pipe']);
		$this->app->registerMethod('processForward', [$this, 'processForward']);
		$this->app->registerMethod('process', [$this, 'process']);
		$this->app->registerMethod('handle', [$this, 'handle']);

		if (! $this->app->isCli()) {
			$this->app->registerMethod('run', [$this, 'run']);
		}

		$init->on(ConfigConfigureEvent::class, [$this, 'onConfigConfigure']);
		$init->on(ContainerReadyEvent::class, [$this, 'onContainerReady']);
	}

	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		$containerConfig = $event->config->get(ContainerConfig::class);

		$containerConfig->addFactories([
			EmitterInterface::class => EmitterFactory::class,
			ErrorHandler::class => ErrorHandlerFactory::class,
			MiddlewareContainer::class => MiddlewareContainerFactory::class,
			ErrorResponseGenerator::class => ErrorResponseGeneratorFactory::class,
			'ON\Whoops' => WhoopsFactory::class,
			'ON\WhoopsPageHandler' => WhoopsPageHandlerFactory::class,
			ResponseInterface::class => ResponseFactoryFactory::class,
			RequestHandlerRunner::class => RequestHandlerRunnerFactory::class,
			ServerRequestErrorResponseGenerator::class => ServerRequestErrorResponseGeneratorFactory::class,
			ServerRequestInterface::class => ServerRequestFactoryFactory::class,
			StreamInterface::class => StreamFactoryFactory::class,
			NotFoundHandler::class => NotFoundHandlerFactory::class,
		]);

		$containerConfig->addAliases([
			MiddlewarePipeInterface::class => MiddlewarePriorityPipe::class,
			MiddlewareFactoryInterface::class => MiddlewareFactory::class,
			RequestStackInterface::class => RequestStack::class,
			ResponseFactoryInterface::class => ResponseFactory::class,
		]);
	}

	public function onContainerReady(ContainerReadyEvent $event, InitContext $context): void
	{
		$this->container = $event->container;

		// Bring the runtime pipeline online before loading user pipeline config,
		// so queued middleware and config-defined middleware resolve consistently.
		$this->setupPipeline($event->container);
		$this->registerMiddlewares();
		$this->flush();

		$appCfg = $this->app->config->get(AppConfig::class);
		$this->loadPipeline($appCfg->get('app.pipeline_file', 'config/pipeline.php'));

		$context->emit(new PipelineReadyEvent($this, $this->container));
	}

	public function registerMiddlewares(): void
	{
		// Core middleware order matters:
		// - Error handling wraps everything
		// - Request/body setup runs before business middleware
		// - Validation runs before execution
		// - NotFound stays at the end as the final fallback
		$this->pipe('/', ErrorHandler::class, 1000);
		$this->pipe('/', RegisterRequestMiddleware::class, 900);
		$this->pipe('/', BodyParamsMiddleware::class, 900);
		$this->pipe('/', ValidationMiddleware::class, 1);
		$this->pipe('/', ExecutionMiddleware::class, 0);
		$this->pipe('/', NotFoundMiddleware::class, -1);
	}

	protected function setupPipeline(ContainerInterface $container): void
	{
		$this->app->requestStack = $this->requestStack = $container->get(RequestStack::class);
		$this->pipeline = $container->get(MiddlewarePipeInterface::class);
		$this->factory = $container->get(MiddlewareFactory::class);
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

	public function flush(): void
	{
		// Middleware can be registered before the pipeline exists; replay those
		// registrations once the container-backed pipeline is available.
		while ($item = array_shift($this->q)) {
			$this->pipe($item[0], $item[1], $item[2]);
		}
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
	public function pipe($middlewareOrPath, $middleware = null, $priority = 1): void
	{
		if (! isset($this->pipeline)) {
			// Delay registration until container services such as middleware
			// factories and the pipeline itself are ready.
			$this->q[] = [$middlewareOrPath, $middleware, $priority];

			return;
		}

		$middleware = $middleware ?? $middlewareOrPath;
		$path = $middleware === $middlewareOrPath ? '/' : $middlewareOrPath;

		// Path-specific middleware is wrapped before being inserted into the
		// priority pipe; root middleware is inserted directly.
		$middleware = $path !== '/'
			? path($path, $this->prepareMiddleware($middleware))
			: $this->prepareMiddleware($middleware);

		$this->pipeline->pipe($middleware, $priority);
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		return $this->pipeline->handle($this->prepareRequest($request));
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->pipeline->process($this->prepareRequest($request), $handler);
	}

	public function addRequestPreparer(RequestPreparerInterface $preparer, int $priority = 0): void
	{
		$this->requestPreparers[] = [
			'priority' => $priority,
			'preparer' => $preparer,
		];

		usort(
			$this->requestPreparers,
			static fn(array $left, array $right): int => $right['priority'] <=> $left['priority']
		);
	}

	protected function prepareRequest(ServerRequestInterface $request): ServerRequestInterface
	{
		foreach ($this->requestPreparers as $entry) {
			$request = $entry['preparer']->prepare($request);
		}

		return $request;
	}

	public function createForwardRequest(
		string $path,
		?ServerRequestInterface $request = null,
		?string $method = null
	): ServerRequestInterface
	{
		if (! $request) {
			$request = ServerRequestFactory::fromGlobals();
		}

		// Forwarded requests carry a fresh RequestContext that points back to the
		// current request, allowing preparers to infer request lineage without an
		// extra marker attribute.
		$request = $request
			->withAttribute(
				RequestContext::class,
				new RequestContext(
					$request,
					$request->getAttribute(RequestContext::class)
				)
			)
			->withUri(new Uri($path));

		if ($method !== null) {
			$request = $request->withMethod($method);
		}

		return $request;
	}

	public function processForward(
		string $path,
		ServerRequestInterface $request,
		?string $method = null
	): ResponseInterface
	{
		// Forwarding stays transport-level here: the caller provides the target
		// URI/path and routing middleware prepares the next execution state.
		return $this->handle($this->createForwardRequest($path, $request, $method));
	}

	public function run(): void
	{
		if ($dispatcher = $this->app->ext('events')) {
			$dispatcher->dispatch(new NamedEvent('core.run'));
		}

		$this->runner->run();

		if ($dispatcher = $this->app->ext('events')) {
			$dispatcher->dispatch(new NamedEvent('core.end'));
		}
	}
}
