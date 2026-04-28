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
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Router\Route;
use ON\Router\RouteResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
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
		$page = new class {
			public function isSecure(): bool { return true; }
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
		$page = new class {
			public function isSecure(): bool { return true; }
		};

		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->method('hasIdentity')->willReturn(false);

		$forwardResponse = new HtmlResponse('login');
		$app = new class($forwardResponse) extends Application {
			public function __construct(private ResponseInterface $response) {}

			public function processForward($middleware, $request): ResponseInterface
			{
				return $this->response;
			}
		};

		$config = new AppConfig();
		$config->set('controllers.login', 'LoginPage::index');

		$middleware = new SecurityMiddleware($auth, $app, $config);
		$request = (new ServerRequest())->withAttribute(RouteResult::class, $this->createRouteResult($page, 'index'));

		$response = $middleware->process($request, $this->createMock(RequestHandlerInterface::class));

		$this->assertStringContainsString('login', (string) $response->getBody());
	}

	public function testAuthorizationMiddlewareRequiresExplicitTrueResult(): void
	{
		$page = new class {
			public function checkPermissions(): int { return 1; }
			public function index(): JsonResponse { return new JsonResponse(['ok' => true]); }
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
		$page = new class {
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
		$page = new class {
			public ?string $path = null;

			public function checkPermissions(\Psr\Http\Message\ServerRequestInterface $request): bool
			{
				$this->path = $request->getUri()->getPath();

				return true;
			}
		};

		$middleware = new AuthorizationMiddleware($this->executor, $this->createMock(Application::class), new AppConfig());
		$request = (new ServerRequest())
			->withUri(new \Laminas\Diactoros\Uri('/auth-check'))
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
