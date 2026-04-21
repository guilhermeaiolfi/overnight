<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Auth\AuthenticationServiceInterface;
use ON\Auth\Middleware\AuthorizationMiddleware;
use ON\Auth\Middleware\SecurityMiddleware;
use ON\Config\AppConfig;
use ON\Container\Executor\Executor;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Middleware\ExecutionMiddleware;
use ON\Middleware\ValidationMiddleware;
use ON\Router\Route;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use ON\View\ViewInterface;
use ON\View\ViewManager;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Proves that a plain class (no base class, no interface) works as a page
 * through ExecutionMiddleware and ValidationMiddleware.
 */
final class PlainPageIntegrationTest extends TestCase
{
	private ContainerInterface $container;
	private Executor $executor;

	protected function setUp(): void
	{
		$this->container = $this->createMock(ContainerInterface::class);

		$parameterResolver = new ResolverChain([
			new TypeHintResolver(),
			new NumericArrayResolver(),
			new AssociativeArrayResolver(),
			new DefaultValueResolver(),
			new TypeHintContainerResolver($this->container),
		]);

		$this->executor = new Executor($parameterResolver, $this->container);
	}

	public function testPlainPageReturnsJsonResponse(): void
	{
		$page = new class {
			public function index(): JsonResponse
			{
				return new JsonResponse(['status' => 'ok']);
			}
		};

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertStringContainsString('ok', (string) $response->getBody());
	}

	public function testPlainPageWithViewResult(): void
	{
		$page = new class {
			public function index(): ViewResult
			{
				return new ViewResult('success', ['message' => 'Hello']);
			}

			public function successView(ViewResult $result): HtmlResponse
			{
				return new HtmlResponse('<h1>' . $result->get('message') . '</h1>');
			}
		};

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(HtmlResponse::class, $response);
		$this->assertStringContainsString('Hello', (string) $response->getBody());
	}

	public function testPlainPageWithRouteParams(): void
	{
		$page = new class {
			public ?int $receivedId = null;

			public function show(int $id): JsonResponse
			{
				$this->receivedId = $id;
				return new JsonResponse(['id' => $id]);
			}
		};

		$routeResult = $this->createRouteResult($page, 'show', ['id' => '42']);
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertSame(42, $page->receivedId);
	}

	public function testPlainPageWithValidation(): void
	{
		$page = new class {
			public function createValidate(ServerRequestInterface $request): bool
			{
				$body = $request->getParsedBody();
				return !empty($body['title']);
			}

			public function create(ServerRequestInterface $request): JsonResponse
			{
				return new JsonResponse(['created' => true]);
			}
		};

		// Valid request — validation passes, action runs
		$routeResult = $this->createRouteResult($page, 'create');
		$request = (new ServerRequest())
			->withAttribute(RouteResult::class, $routeResult)
			->withParsedBody(['title' => 'My Post']);

		$actionResponse = new JsonResponse(['created' => true]);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($actionResponse);

		$validationMiddleware = $this->createValidationMiddleware();
		$response = $validationMiddleware->process($request, $handler);

		// Validation passed → handler was called → action response returned
		$this->assertSame($actionResponse, $response);
	}

	public function testPlainPageValidationFailsWithoutErrorHandler(): void
	{
		$page = new class {
			public function createValidate(ServerRequestInterface $request): bool
			{
				return false; // always fails
			}

			public function create(): JsonResponse
			{
				return new JsonResponse(['should' => 'not reach']);
			}
		};

		$routeResult = $this->createRouteResult($page, 'create');
		$request = (new ServerRequest())
			->withAttribute(RouteResult::class, $routeResult)
			->withParsedBody([]);

		$fallthrough = new JsonResponse(['fallthrough' => true]);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($fallthrough);

		$validationMiddleware = $this->createValidationMiddleware();
		$response = $validationMiddleware->process($request, $handler);

		// No error handler → passes through to handler
		$this->assertSame($fallthrough, $response);
	}

	public function testPlainPageWithStringReturn(): void
	{
		$page = new class {
			public function index(): string
			{
				return 'Success';
			}

			public function successView(ViewResult $result): HtmlResponse
			{
				return new HtmlResponse('rendered success');
			}
		};

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$response = $middleware->process($request, $handler);

		$this->assertStringContainsString('rendered success', (string) $response->getBody());
	}

	public function testPlainPageWithViewInterface(): void
	{
		$mockView = $this->createMock(ViewInterface::class);
		$mockView->method('render')->willReturn('<html>rendered</html>');

		$page = new class($mockView) {
			public function __construct(public ViewInterface $view) {}

			public function index(): ViewResult
			{
				return new ViewResult('success', ['title' => 'Test']);
			}

			public function successView(ViewResult $result): HtmlResponse
			{
				return new HtmlResponse($this->view->render($result->toArray(), 'page/index'));
			}
		};

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$response = $middleware->process($request, $handler);

		$this->assertStringContainsString('rendered', (string) $response->getBody());
	}

	protected function createRouteResult(object $page, string $method, array $params = []): RouteResult
	{
		$route = new Route('/test', 'Page::' . $method);
		$routeResult = RouteResult::fromRoute($route, $params);
		$routeResult->setTargetInstance($page);
		$routeResult->setMethod($method);
		return $routeResult;
	}

	protected function createExecutionMiddleware(): ExecutionMiddleware
	{
		return new ExecutionMiddleware(
			$this->createMock(RouterInterface::class),
			$this->executor,
			$this->createViewManager()
		);
	}

	protected function createValidationMiddleware(): ValidationMiddleware
	{
		return new ValidationMiddleware($this->executor, $this->createViewManager());
	}

	protected function createViewManager(): ViewManager
	{
		$router = $this->createMock(RouterInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')
			->willReturnCallback(fn(string $class): bool => $class === RouterInterface::class);
		$container->method('get')
			->willReturnCallback(fn(string $class): mixed => $class === RouterInterface::class ? $router : null);

		return new ViewManager(new ViewConfig(), $container);
	}

	// --- isSecure (SecurityMiddleware) ---

	public function testSecurePageBlocksUnauthenticatedUser(): void
	{
		$page = new class {
			public function isSecure(): bool { return true; }
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->method('hasIdentity')->willReturn(false);

		$forwardResponse = new HtmlResponse('login page');
		$app = new class($forwardResponse) extends Application {
			private ResponseInterface $forwardResponse;
			public function __construct(ResponseInterface $r) { $this->forwardResponse = $r; }
			public function processForward($middleware, $request): ResponseInterface {
				return $this->forwardResponse;
			}
		};

		$config = new AppConfig();
		$config->set('controllers.login', 'LoginPage::index');

		$middleware = new SecurityMiddleware($auth, $app, $config);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		$response = $middleware->process($request, $handler);

		$this->assertStringContainsString('login page', (string) $response->getBody());
	}

	public function testSecurePageAllowsAuthenticatedUser(): void
	{
		$page = new class {
			public function isSecure(): bool { return true; }
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->method('hasIdentity')->willReturn(true);

		$app = $this->createMock(Application::class);
		$config = new AppConfig();

		$middleware = new SecurityMiddleware($auth, $app, $config);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expectedResponse = new JsonResponse(['ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testNonSecurePagePassesThrough(): void
	{
		$page = new class {
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$app = $this->createMock(Application::class);
		$config = new AppConfig();

		$middleware = new SecurityMiddleware($auth, $app, $config);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expectedResponse = new JsonResponse(['ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	// --- checkPermissions (AuthorizationMiddleware) ---

	public function testCheckPermissionsBlocksDeniedUser(): void
	{
		$page = new class {
			public function checkPermissions(): bool { return false; }
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$app = $this->createMock(Application::class);
		$config = new AppConfig();

		$middleware = new AuthorizationMiddleware($this->executor, $app, $config);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		$response = $middleware->process($request, $handler);

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testCheckPermissionsAllowsAuthorizedUser(): void
	{
		$page = new class {
			public function checkPermissions(): bool { return true; }
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$app = $this->createMock(Application::class);
		$config = new AppConfig();

		$middleware = new AuthorizationMiddleware($this->executor, $app, $config);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expectedResponse = new JsonResponse(['ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testActionSpecificCheckPermissions(): void
	{
		$page = new class {
			public function checkCreatePermissions(): bool { return false; }
			public function checkPermissions(): bool { return true; } // should NOT be called
			public function create(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$app = $this->createMock(Application::class);
		$config = new AppConfig();

		$middleware = new AuthorizationMiddleware($this->executor, $app, $config);

		$routeResult = $this->createRouteResult($page, 'create');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$response = $middleware->process($request, $handler);

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testNoCheckPermissionsPassesThrough(): void
	{
		$page = new class {
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$app = $this->createMock(Application::class);
		$config = new AppConfig();

		$middleware = new AuthorizationMiddleware($this->executor, $app, $config);

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expectedResponse = new JsonResponse(['ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	// --- validate (ValidationMiddleware) ---

	public function testValidatePassesOnPlainPage(): void
	{
		$page = new class {
			public function validate(): bool { return true; }
			public function index(): JsonResponse { return new JsonResponse(['ok']); }
		};

		$middleware = $this->createValidationMiddleware();

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expectedResponse = new JsonResponse(['ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testValidateFailsWithErrorHandlerOnPlainPage(): void
	{
		$page = new class {
			public function validate(): bool { return false; }
			public function handleError(): ViewResult { return new ViewResult('error', ['msg' => 'bad']); }
			public function errorView(ViewResult $result): HtmlResponse {
				return new HtmlResponse('Error: ' . $result->get('msg'));
			}
		};

		$middleware = $this->createValidationMiddleware();

		$routeResult = $this->createRouteResult($page, 'index');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$response = $middleware->process($request, $handler);

		$this->assertStringContainsString('Error: bad', (string) $response->getBody());
	}

	public function testActionSpecificValidateOnPlainPage(): void
	{
		$page = new class {
			public function createValidate(): bool { return true; }
			public function validate(): bool { return false; } // should NOT be called
			public function create(): JsonResponse { return new JsonResponse(['created']); }
		};

		$middleware = $this->createValidationMiddleware();

		$routeResult = $this->createRouteResult($page, 'create');
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expectedResponse = new JsonResponse(['created']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}
}
