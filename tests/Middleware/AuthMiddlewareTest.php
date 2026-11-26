<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Closure;
use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use ON\Application;
use ON\Auth\AuthenticationServiceInterface;
use ON\Auth\AuthExtension;
use ON\Auth\Middleware\AuthorizationMiddleware;
use ON\Auth\Middleware\SecurityMiddleware;
use ON\Config\AppConfig;
use ON\Container\Executor\Executor;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Middleware\ExecutionMiddleware;
use ON\Middleware\MiddlewarePriorityPipe;
use ON\Middleware\NotFoundMiddleware;
use ON\Middleware\PipelineExtension;
use ON\Middleware\ValidationMiddleware;
use ON\RequestStack;
use ON\Router\Route;
use ON\Router\RouteResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddlewareTest extends TestCase
{
	private Executor $executor;

	protected function setUp(): void
	{
		$container = $this->createMock(ContainerInterface::class);

		$parameterResolver = new ResolverChain([
			new TypeHintResolver(),
			new NumericArrayResolver(),
			new AssociativeArrayResolver(),
			new DefaultValueResolver(),
			new TypeHintContainerResolver($container),
		]);

		$this->executor = new Executor($parameterResolver, $container);
	}

	public function testSecurityMiddlewareReturns401WhenLoginRouteIsMissing(): void
	{
		$page = new class () {
			public function isSecure(): bool
			{
				return true;
			}
		};

		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->method('hasIdentity')->willReturn(false);

		$middleware = new SecurityMiddleware($auth, $this->createMock(Application::class), new AppConfig());
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $this->createRouteResult($page, 'index'));
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		$response = $middleware->process($request, $handler);

		$this->assertSame(401, $response->getStatusCode());
	}

	public function testSecurityMiddlewareForwardsToConfiguredLoginRoute(): void
	{
		$page = new class () {
			public function isSecure(): bool
			{
				return true;
			}
		};

		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->method('hasIdentity')->willReturn(false);

		$forwardResponse = new HtmlResponse('login');
		$app = new class ($forwardResponse) extends Application {
			public ?string $forwardedMethod = null;
			public ?string $forwardedPath = null;

			public function __construct(private ResponseInterface $response)
			{
			}

			public function processForward($path, $request, ?string $method = null): ResponseInterface
			{
				$this->forwardedPath = $path;
				$this->forwardedMethod = $method;

				return $this->response;
			}
		};

		$config = new AppConfig();
		$config->set('controllers.login', '/login');

		$middleware = new SecurityMiddleware($auth, $app, $config);
		$request = (new ServerRequest())
			->withMethod('POST')
			->withAttribute(RouteResult::class, $this->createRouteResult($page, 'index'));

		$response = $middleware->process($request, $this->createMock(RequestHandlerInterface::class));

		$this->assertStringContainsString('login', (string) $response->getBody());
		$this->assertSame('/login', $app->forwardedPath);
		$this->assertSame('GET', $app->forwardedMethod);
	}

	public function testSecurityMiddlewarePassesThroughWhenTargetInstanceIsNull(): void
	{
		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$middleware = new SecurityMiddleware($auth, $this->createMock(Application::class), new AppConfig());

		$route = new Route('/test', $this->createMock(MiddlewareInterface::class));
		$routeResult = RouteResult::fromRoute($route);
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expected = new JsonResponse(['ok' => true]);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($expected);

		$this->assertSame($expected, $middleware->process($request, $handler));
	}

	public function testAuthorizationMiddlewarePassesThroughWhenTargetInstanceIsNull(): void
	{
		$middleware = new AuthorizationMiddleware($this->executor, $this->createMock(Application::class), new AppConfig());

		$route = new Route('/test', $this->createMock(MiddlewareInterface::class));
		$routeResult = RouteResult::fromRoute($route);
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$expected = new JsonResponse(['ok' => true]);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($expected);

		$this->assertSame($expected, $middleware->process($request, $handler));
	}

	public function testAuthorizationMiddlewareForwardsTo403WithGetMethod(): void
	{
		$page = new class () {
			public function checkPermissions(): bool
			{
				return false;
			}
		};

		$forwardResponse = new HtmlResponse('forbidden', 403);
		$app = new class ($forwardResponse) extends Application {
			public ?string $forwardedMethod = null;
			public ?string $forwardedPath = null;

			public function __construct(private ResponseInterface $response)
			{
			}

			public function processForward($path, $request, ?string $method = null): ResponseInterface
			{
				$this->forwardedPath = $path;
				$this->forwardedMethod = $method;

				return $this->response;
			}
		};

		$config = new AppConfig();
		$config->set('controllers.errors.403', '/403');

		$middleware = new AuthorizationMiddleware($this->executor, $app, $config);
		$request = (new ServerRequest())
			->withMethod('POST')
			->withAttribute(RouteResult::class, $this->createRouteResult($page, 'index'));

		$response = $middleware->process($request, $this->createMock(RequestHandlerInterface::class));

		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('/403', $app->forwardedPath);
		$this->assertSame('GET', $app->forwardedMethod);
	}

	public function testAuthMiddlewaresRunStrictlyBeforeValidationMiddleware(): void
	{
		$pipe = new MiddlewarePriorityPipe(new RequestStack());
		$recorded = [];

		$makeMiddleware = static function (string $name) use (&$recorded): MiddlewareInterface {
			return new class ($name, $recorded) implements MiddlewareInterface {
				public function __construct(private string $name, private array &$recorded)
				{
				}

				public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
				{
					$this->recorded[] = $this->name;

					return $handler->handle($request);
				}
			};
		};

		$app = new class ($pipe, $makeMiddleware) extends Application {
			public function __construct(
				private MiddlewarePriorityPipe $pipe,
				private Closure $makeMiddleware
			) {
			}

			public function pipe($middlewareOrPath, $middleware = null, $priority = 1): void
			{
				$name = $middleware ?? $middlewareOrPath;
				$this->pipe->pipe(($this->makeMiddleware)($name), $priority);
			}
		};

		// Simulate PipelineExtension::registerMiddlewares() running before AuthExtension::onPipelineReady()
		$pipelineExt = new class ($app) extends PipelineExtension {
			public function pipe($middlewareOrPath, $middleware = null, $priority = 1): void
			{
				$this->app->pipe($middlewareOrPath, $middleware, $priority);
			}
		};

		$pipelineExt->registerMiddlewares();

		$authExt = new AuthExtension($app);
		$authExt->onPipelineReady();

		$pipe->process(new ServerRequest(), new class () implements RequestHandlerInterface {
			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return new JsonResponse(['ok' => true]);
			}
		});

		$securityIdx = array_search(SecurityMiddleware::class, $recorded, true);
		$authzIdx = array_search(AuthorizationMiddleware::class, $recorded, true);
		$validationIdx = array_search(ValidationMiddleware::class, $recorded, true);
		$executionIdx = array_search(ExecutionMiddleware::class, $recorded, true);
		$notFoundIdx = array_search(NotFoundMiddleware::class, $recorded, true);

		$this->assertNotFalse($securityIdx);
		$this->assertNotFalse($authzIdx);
		$this->assertNotFalse($validationIdx);
		$this->assertLessThan($authzIdx, $securityIdx);
		$this->assertLessThan($validationIdx, $authzIdx);
		$this->assertLessThan($executionIdx, $validationIdx);
		$this->assertLessThan($notFoundIdx, $executionIdx);
	}

	public function testAuthorizationMiddlewareRequiresExplicitTrueResult(): void
	{
		$page = new class () {
			public function checkPermissions(): int
			{
				return 1;
			}

			public function index(): JsonResponse
			{
				return new JsonResponse(['ok' => true]);
			}
		};

		$middleware = new AuthorizationMiddleware($this->executor, $this->createMock(Application::class), new AppConfig());
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $this->createRouteResult($page, 'index'));
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		$response = $middleware->process($request, $handler);

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testAuthorizationMiddlewarePrefersActionSpecificPermissionHook(): void
	{
		$page = new class () {
			public int $genericChecks = 0;
			public int $actionChecks = 0;

			public function checkCreatePermissions(): bool
			{
				$this->actionChecks++;

				return true;
			}

			public function checkPermissions(): bool
			{
				$this->genericChecks++;

				return false;
			}
		};

		$middleware = new AuthorizationMiddleware($this->executor, $this->createMock(Application::class), new AppConfig());
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $this->createRouteResult($page, 'create'));
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->willReturn(new JsonResponse(['ok' => true]));

		$response = $middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(1, $page->actionChecks);
		$this->assertSame(0, $page->genericChecks);
	}

	public function testAuthorizationMiddlewareInjectsRequestIntoPermissionHook(): void
	{
		$page = new class () {
			public ?string $path = null;

			public function checkPermissions(ServerRequestInterface $request): bool
			{
				$this->path = $request->getUri()->getPath();

				return true;
			}
		};

		$middleware = new AuthorizationMiddleware($this->executor, $this->createMock(Application::class), new AppConfig());
		$request = (new ServerRequest())
			->withUri(new Uri('/auth-check'))
			->withAttribute(RouteResult::class, $this->createRouteResult($page, 'index'));
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->willReturn(new JsonResponse(['ok' => true]));

		$response = $middleware->process($request, $handler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('/auth-check', $page->path);
	}

	private function createRouteResult(object $page, string $method): RouteResult
	{
		$route = new Route('/test', 'Page::' . $method);
		$routeResult = RouteResult::fromRoute($route);
		$routeResult->setTargetInstance($page);
		$routeResult->setMethod($method);

		return $routeResult;
	}
}
