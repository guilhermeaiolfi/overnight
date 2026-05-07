<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Container\Executor\Executor;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Http\InvocationContext;
use ON\Middleware\ExecutionMiddleware;
use ON\RequestStack;
use ON\RequestStackInterface;
use ON\Router\Container\UrlHelperFactory;
use ON\Router\Middleware\RouteMiddleware;
use ON\Router\Route;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\Router\UrlHelper;
use ON\View\ViewConfig;
use ON\View\ViewManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\Fixtures\TestPage;

final class ExecutionInvocationPayload
{
	public function __construct(public string $value)
	{
	}
}

final class ExecutionMiddlewareTest extends TestCase
{
	private ContainerInterface $container;
	private Executor $executor;
	private TestPage $page;

	protected function setUp(): void
	{
		$this->page = new TestPage();
		$this->container = $this->createMock(ContainerInterface::class);
		$router = $this->createMock(RouterInterface::class);
		$requestStack = new RequestStack();
		$urlHelperFactory = new UrlHelperFactory();

		$this->container->method('has')
			->willReturnCallback(fn(string $class): bool => in_array($class, [
				UrlHelper::class,
				RouterInterface::class,
				RequestStackInterface::class,
				TestPage::class,
			], true));
		$this->container->method('get')
			->willReturnCallback(function (string $class) use ($router, $requestStack, $urlHelperFactory): mixed {
				return match ($class) {
					RouterInterface::class => $router,
					RequestStackInterface::class => $requestStack,
					TestPage::class => $this->page,
					UrlHelper::class => $urlHelperFactory($this->container),
					default => null,
				};
			});

		$parameterResolver = new ResolverChain([
			new TypeHintResolver(),
			new NumericArrayResolver(),
			new AssociativeArrayResolver(),
			new DefaultValueResolver(),
			new TypeHintContainerResolver($this->container),
		]);

		$this->executor = new Executor($parameterResolver, $this->container);
	}

	public function testPassesThroughWhenNoRouteResult(): void
	{
		$middleware = $this->createExecutionMiddleware();

		$request = new ServerRequest();
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testDelegatesToPsr15Middleware(): void
	{
		$executionMiddleware = $this->createExecutionMiddleware();

		$expectedResponse = new TextResponse('from psr15');
		$innerMiddleware = $this->createMock(MiddlewareInterface::class);
		$innerMiddleware->method('process')->willReturn($expectedResponse);

		$route = new Route('/test', $innerMiddleware);
		$routeResult = RouteResult::fromRoute($route);
		$routeResult->setTargetInstance($innerMiddleware);
		$routeResult->setMethod('process');

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$response = $executionMiddleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testRouteParamsAreInjectedAsMethodArguments(): void
	{
		$this->page->resetTestData();

		$route = new Route('/test/{id}/{name}', TestPage::class . '::testIt');
		$routeResult = RouteResult::fromRoute($route, ['id' => '42', 'name' => 'test']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertSame(42, $this->page->testData['testIt']['id']);
		$this->assertSame('test', $this->page->testData['testIt']['name']);
	}

	public function testTypeCastingForIntegers(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testInt', ['id' => '123']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertSame(123, $this->page->testData['testInt']['id']);
		$this->assertSame('integer', $this->page->testData['testInt']['type']);
	}

	public function testTypeCastingForFloats(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testFloat', ['price' => '19.99']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertEqualsWithDelta(19.99, $this->page->testData['testFloat']['price'], 0.001);
		$this->assertSame('double', $this->page->testData['testFloat']['type']);
	}

	public function testTypeCastingForBooleans(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testBool', ['active' => '1']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertTrue($this->page->testData['testBool']['active']);
		$this->assertSame('boolean', $this->page->testData['testBool']['type']);
	}

	public function testOptionalParamNotProvidedGetsNull(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testItOptionalParam', ['other' => 'value']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertArrayHasKey('testItOptionalParam', $this->page->testData);
		$this->assertNull($this->page->testData['testItOptionalParam']['id']);
	}

	public function testServerRequestInterfaceStillWorks(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testItWithServerRequest', []);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertInstanceOf(ServerRequestInterface::class, $this->page->testData['testItWithServerRequest']['request']);
	}

	public function testMixedRouteParamsAndServerRequest(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testItWithBoth', ['id' => '100']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertSame(100, $this->page->testData['testItWithBoth']['id']);
		$this->assertInstanceOf(ServerRequestInterface::class, $this->page->testData['testItWithBoth']['request']);
	}

	public function testRenderContextAwareHelpersAreInjectedIntoActions(): void
	{
		$router = $this->createMock(RouterInterface::class);
		$router->method('getBasePath')->willReturn('');

		$route = new Route('/logout', 'Page::index');
		$routeResult = RouteResult::fromRoute($route);

		$page = new class {
			public ?UrlHelper $url = null;

			public function index(UrlHelper $url): TextResponse
			{
				$this->url = $url;

				return new TextResponse('ok');
			}
		};

		$routeResult->setTargetInstance($page);
		$routeResult->setMethod('index');

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$stack = $this->container->get(RequestStackInterface::class);
		$stack->push($request);

		$middleware = new ExecutionMiddleware($router, $this->executor, new ViewManager(new ViewConfig(), $this->container, $stack));
		$response = $middleware->process($request, $handler);
		$stack->pop();

		$this->assertSame('ok', (string) $response->getBody());
		$this->assertInstanceOf(UrlHelper::class, $page->url);
	}

	public function testUntypedParameterGetsStringValue(): void
	{
		$this->page->resetTestData();

		$routeResult = $this->createRouteResult('testItUntyped', ['id' => '456']);

		$request = $this->prepareRequest($routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = $this->createExecutionMiddleware();
		$middleware->process($request, $handler);

		$this->assertSame('456', $this->page->testData['testItUntyped']['id']);
		$this->assertSame('string', $this->page->testData['testItUntyped']['type']);
	}

	public function testInvocationContextValuesAreInjectedIntoAction(): void
	{
		$route = new Route('/test', 'Page::index');
		$routeResult = RouteResult::fromRoute($route);

		$dto = new ExecutionInvocationPayload('typed');

		$page = new class {
			public ?string $token = null;
			public ?ExecutionInvocationPayload $dto = null;

			public function index(string $token, ExecutionInvocationPayload $dto): TextResponse
			{
				$this->token = $token;
				$this->dto = $dto;

				return new TextResponse('ok');
			}
		};

		$routeResult->setTargetInstance($page);
		$routeResult->setMethod('index');

		$context = InvocationContext::empty()
			->with('token', 'abc123')
			->withTyped($dto);

		$request = (new ServerRequest())
			->withAttribute(RouteResult::class, $routeResult)
			->withAttribute(InvocationContext::class, $context);

		$handler = $this->createMock(RequestHandlerInterface::class);

		$response = $this->createExecutionMiddleware()->process($request, $handler);

		$this->assertSame('ok', (string) $response->getBody());
		$this->assertSame('abc123', $page->token);
		$this->assertSame($dto, $page->dto);
	}

	public function testInvocationContextRejectsReservedNamedKeys(): void
	{
		foreach (['request', 'handler', 'delegate'] as $key) {
			try {
				InvocationContext::empty()->with($key, new \stdClass());
				$this->fail(sprintf('Expected reserved key "%s" to be rejected.', $key));
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString($key, $e->getMessage());
			}
		}
	}

	public function testInvocationContextRejectsReservedTypedRequestObjects(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage(ServerRequestInterface::class);

		InvocationContext::empty()->withTyped(new ServerRequest());
	}

	public function testInvocationContextRejectsReservedTypedHandlerObjects(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage(RequestHandlerInterface::class);

		$handler = $this->createMock(RequestHandlerInterface::class);
		InvocationContext::empty()->withTyped($handler);
	}

	protected function createRouteResult(string $method, array $params): RouteResult
	{
		$route = new Route('/test', TestPage::class . '::' . $method);
		return RouteResult::fromRoute($route, $params);
	}

	protected function prepareRequest(RouteResult $routeResult): ServerRequestInterface
	{
		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$router = $this->createMock(RouterInterface::class);
		$router->method('match')->willReturn($routeResult);

		$middleware = new RouteMiddleware($router, $app, $this->container);
		$capture = new class implements RequestHandlerInterface {
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

	protected function createExecutionMiddleware(): ExecutionMiddleware
	{
		return new ExecutionMiddleware(
			$this->createMock(RouterInterface::class),
			$this->executor,
			new ViewManager(new ViewConfig(), $this->container, new RequestStack())
		);
	}
}
