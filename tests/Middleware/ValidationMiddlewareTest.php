<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Container\Executor\ExecutorInterface;
use ON\Http\InvocationContext;
use ON\Middleware\ValidationMiddleware;
use ON\Middleware\ValidationResult;
use ON\RequestStack;
use ON\Router\Middleware\RouteMiddleware;
use ON\Router\Route;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use ON\View\ViewManager;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ValidationInvocationErrorBag
{
	public function __construct(public array $messages)
	{
	}
}

final class ValidationMiddlewareTest extends TestCase
{
	protected ContainerInterface $container;

	protected function setUp(): void
	{
		$this->container = $this->createMock(ContainerInterface::class);
	}

	public function testPassesThroughWhenNoRouteResult(): void
	{
		$executor = $this->createMock(ExecutorInterface::class);
		$middleware = $this->createValidationMiddleware($executor);

		$request = new ServerRequest();
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testPassesThroughWhenNoTargetInstance(): void
	{
		$executor = $this->createMock(ExecutorInterface::class);
		$middleware = $this->createValidationMiddleware($executor);

		$route = new Route('/test', $this->createMock(MiddlewareInterface::class));
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testPassesThroughWhenNoValidateMethod(): void
	{
		$page = new class () {
			public function index(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->never())->method('execute');

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testCallsActionSpecificValidateMethod(): void
	{
		$page = new class () {
			public function createValidate(): bool
			{
				return true;
			}

			public function create(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->with(
				[$page, 'createValidate'],
				$this->callback(fn ($args) => isset($args[ServerRequestInterface::class]))
			)
			->willReturn(true);

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'create');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testFallsBackToGenericValidateMethod(): void
	{
		$page = new class () {
			public function validate(): bool
			{
				return true;
			}

			public function index(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->with([$page, 'validate'], $this->anything())
			->willReturn(true);

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('ok'));

		$middleware->process($request, $handler);
	}

	public function testFallsBackToDefaultValidateMethod(): void
	{
		$page = new class () {
			public function defaultValidate(): bool
			{
				return true;
			}

			public function index(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->with([$page, 'defaultValidate'], $this->anything())
			->willReturn(true);

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('ok'));

		$middleware->process($request, $handler);
	}

	public function testCallsErrorHandlerOnValidationFailure(): void
	{
		$page = new class () {
			public function validate(): bool
			{
				return false;
			}

			public function handleError(): string
			{
				return 'Error';
			}

			public function errorView(ViewResult $result, $request = null, $delegate = null): ResponseInterface
			{
				return new HtmlResponse('validation error');
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->exactly(3))
			->method('execute')
			->willReturnCallback(function ($callable, $args) {
				if ($callable[1] === 'validate') {
					return false;
				}
				if ($callable[1] === 'handleError') {
					return 'Error';
				}
				if ($callable[1] === 'errorView') {
					return new HtmlResponse('validation error');
				}

				return null;
			});

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('should not reach'));

		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(ResponseInterface::class, $response);
	}

	public function testPassesThroughWhenValidationFailsButNoErrorHandler(): void
	{
		$page = new class () {
			public function validate(): bool
			{
				return false;
			}

			public function index(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->willReturn(false);

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('fallthrough');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testPrefersActionSpecificOverGenericValidate(): void
	{
		$page = new class () {
			public function createValidate(): bool
			{
				return true;
			}

			public function validate(): bool
			{
				return false;
			}

			public function create(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->with([$page, 'createValidate'], $this->anything())
			->willReturn(true);

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'create');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('ok'));

		$middleware->process($request, $handler);
	}

	public function testValidationResultExtrasAreStoredInInvocationContextForErrorHandling(): void
	{
		$errorBag = new ValidationInvocationErrorBag(['email' => 'Invalid email']);

		$page = new class ($errorBag) {
			public ?string $token = null;
			public ?ValidationInvocationErrorBag $errors = null;

			public function __construct(private ValidationInvocationErrorBag $errorBag)
			{
			}

			public function validate(ServerRequestInterface $request): ValidationResult
			{
				$context = InvocationContext::fromRequest($request)
					->with('token', 'abc123')
					->withTyped($this->errorBag);
				$request = $request->withAttribute(InvocationContext::class, $context);

				return ValidationResult::fail($request);
			}

			public function handleError(string $token, ValidationInvocationErrorBag $errors): string
			{
				$this->token = $token;
				$this->errors = $errors;

				return 'Error';
			}

			public function errorView(ViewResult $result, $request = null, $delegate = null): ResponseInterface
			{
				return new HtmlResponse('validation error');
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->exactly(3))
			->method('execute')
			->willReturnCallback(function ($callable, $args) use ($page) {
				if ($callable[1] === 'validate') {
					return $page->validate($args[ServerRequestInterface::class]);
				}
				if ($callable[1] === 'handleError') {
					return $page->handleError($args['token'], $args[ValidationInvocationErrorBag::class]);
				}
				if ($callable[1] === 'errorView') {
					return new HtmlResponse('validation error');
				}

				return null;
			});

		$middleware = $this->createValidationMiddleware($executor);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())
			->withAttribute(RouteResult::class, $routeResult)
			->withAttribute(InvocationContext::class, InvocationContext::empty());

		$response = $middleware->process($request, $this->createHandler(new TextResponse('nope')));

		$this->assertInstanceOf(ResponseInterface::class, $response);
		$this->assertSame('abc123', $page->token);
		$this->assertSame($errorBag, $page->errors);
	}

	public function testUpdatedRequestCarriesInvocationContextToNextMiddleware(): void
	{
		$page = new class () {
			public function validate(ServerRequestInterface $request): ValidationResult
			{
				$context = InvocationContext::fromRequest($request)->with('token', 'abc123');
				$request = $request->withAttribute(InvocationContext::class, $context);

				return ValidationResult::valid($request);
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->willReturnCallback(fn ($callable, $args) => $page->validate($args[ServerRequestInterface::class]));

		$middleware = $this->createValidationMiddleware($executor);
		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($this->callback(function (ServerRequestInterface $request): bool {
				$context = InvocationContext::fromRequest($request);

				return $context->get('token') === 'abc123';
			}))
			->willReturn(new TextResponse('ok'));

		$response = $middleware->process($request, $handler);

		$this->assertSame('ok', (string) $response->getBody());
	}

	public function testValidationResultFailurePassesUpdatedRequestToErrorHandler(): void
	{
		$page = new class () {
			public ?string $token = null;

			public function validate(ServerRequestInterface $request): ValidationResult
			{
				$context = InvocationContext::fromRequest($request)->with('token', 'abc123');
				$request = $request->withAttribute(InvocationContext::class, $context);

				return ValidationResult::fail($request);
			}

			public function handleError(string $token): string
			{
				$this->token = $token;

				return 'Error';
			}

			public function errorView(ViewResult $result, $request = null, $delegate = null): ResponseInterface
			{
				return new HtmlResponse('validation error');
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->exactly(3))
			->method('execute')
			->willReturnCallback(function ($callable, $args) use ($page) {
				if ($callable[1] === 'validate') {
					return $page->validate($args[ServerRequestInterface::class]);
				}
				if ($callable[1] === 'handleError') {
					return $page->handleError($args['token']);
				}
				if ($callable[1] === 'errorView') {
					return new HtmlResponse('validation error');
				}

				return null;
			});

		$middleware = $this->createValidationMiddleware($executor);
		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())
			->withAttribute(RouteResult::class, $routeResult)
			->withAttribute(InvocationContext::class, InvocationContext::empty());

		$response = $middleware->process($request, $this->createHandler(new TextResponse('nope')));

		$this->assertInstanceOf(ResponseInterface::class, $response);
		$this->assertSame('abc123', $page->token);
	}

	public function testPreparedRouteParamsAreAvailableToValidateMethod(): void
	{
		$page = new class () {
			public ?int $id = null;

			public function index(): string
			{
				return 'ok';
			}

			public function validate(int $id): bool
			{
				$this->id = $id;

				return true;
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->once())
			->method('execute')
			->willReturnCallback(function ($callable, $args) use ($page) {
				return $page->validate((int) $args['id']);
			});

		$middleware = $this->createValidationMiddleware($executor);
		$routeResult = RouteResult::fromRoute(new Route('/test/{id}', get_class($page) . '::index'), ['id' => '42']);
		$request = $this->prepareRequest($routeResult, [
			get_class($page) => $page,
			RouterInterface::class => $this->createMock(RouterInterface::class),
		]);

		$response = $middleware->process($request, $this->createHandler(new TextResponse('ok')));

		$this->assertSame('ok', (string) $response->getBody());
		$this->assertSame(42, $page->id);
	}

	protected function createHandler(ResponseInterface $response): RequestHandlerInterface
	{
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($response);

		return $handler;
	}

	protected function createRouteResult(object $page, string $method): RouteResult
	{
		$route = new Route('/test', 'Page::' . $method);
		$routeResult = RouteResult::fromRoute($route);
		$routeResult->setTargetInstance($page);
		$routeResult->setMethod($method);

		return $routeResult;
	}

	protected function createValidationMiddleware(ExecutorInterface $executor): ValidationMiddleware
	{
		return new ValidationMiddleware(
			$executor,
			new ViewManager(new ViewConfig(), $this->createViewContainer(), new RequestStack())
		);
	}

	protected function createViewContainer(): ContainerInterface
	{
		$router = $this->createMock(RouterInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')
			->willReturnCallback(fn (string $class): bool => $class === RouterInterface::class);
		$container->method('get')
			->willReturnCallback(fn (string $class): mixed => $class === RouterInterface::class ? $router : null);

		return $container;
	}

	/**
	 * @param array<string, object> $services
	 */
	protected function prepareRequest(RouteResult $routeResult, array $services): ServerRequestInterface
	{
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')
			->willReturnCallback(fn (string $class): bool => array_key_exists($class, $services));
		$container->method('get')
			->willReturnCallback(fn (string $class): mixed => $services[$class] ?? null);

		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$router = $this->createMock(RouterInterface::class);
		$router->method('match')->willReturn($routeResult);
		$middleware = new RouteMiddleware($router, $app, $container);
		$capture = new class () implements RequestHandlerInterface {
			public ?ServerRequestInterface $request = null;

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				$this->request = $request;

				return new TextResponse('ok');
			}
		};

		$middleware->process(new ServerRequest(), $capture);

		return $capture->request ?? new ServerRequest();
	}
}
