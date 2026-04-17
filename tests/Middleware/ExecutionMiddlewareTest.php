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
use ON\Container\Executor\Executor;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Middleware\ExecutionMiddleware;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\Route;
use ON\Router\RouteResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\Fixtures\TestPage;

final class ExecutionMiddlewareTest extends TestCase
{
	private ContainerInterface $container;
	private Executor $executor;
	private TestPage $page;

	protected function setUp(): void
	{
		$this->page = new TestPage();
		$this->container = $this->createMock(ContainerInterface::class);

		$parameterResolver = new ResolverChain([
			new TypeHintResolver(),
			new NumericArrayResolver(),
			new AssociativeArrayResolver(),
			new DefaultValueResolver(),
			new TypeHintContainerResolver($this->container),
		]);

		$this->executor = new Executor($parameterResolver, $this->container);

		$this->container->method('get')
			->willReturnCallback(function (string $class) {
				if ($class === ExecutorInterface::class) {
					return $this->executor;
				}
				if ($class === TestPage::class) {
					return $this->page;
				}
				return null;
			});
	}

	public function testPassesThroughWhenNoRouteResult(): void
	{
		$middleware = new ExecutionMiddleware($this->container, $this->executor);

		$request = new ServerRequest();
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testDelegatesToPsr15Middleware(): void
	{
		$executionMiddleware = new ExecutionMiddleware($this->container, $this->executor);

		$expectedResponse = new TextResponse('from psr15');
		$innerMiddleware = $this->createMock(MiddlewareInterface::class);
		$innerMiddleware->method('process')->willReturn($expectedResponse);

		$route = new Route('/test', $innerMiddleware);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$response = $executionMiddleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testRouteParamsAreInjectedAsMethodArguments(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testIt');
		$route = new Route('/test/{id}/{name}', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['id' => '42', 'name' => 'test']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertSame(42, $this->page->testData['testIt']['id']);
		$this->assertSame('test', $this->page->testData['testIt']['name']);
	}

	public function testTypeCastingForIntegers(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testInt');
		$route = new Route('/test/{id}', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['id' => '123']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertSame(123, $this->page->testData['testInt']['id']);
		$this->assertSame('integer', $this->page->testData['testInt']['type']);
	}

	public function testTypeCastingForFloats(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testFloat');
		$route = new Route('/test/{price}', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['price' => '19.99']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertEqualsWithDelta(19.99, $this->page->testData['testFloat']['price'], 0.001);
		$this->assertSame('double', $this->page->testData['testFloat']['type']);
	}

	public function testTypeCastingForBooleans(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testBool');
		$route = new Route('/test/{active}', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['active' => '1']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertTrue($this->page->testData['testBool']['active']);
		$this->assertSame('boolean', $this->page->testData['testBool']['type']);
	}

	public function testOptionalParamNotProvidedGetsNull(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testItOptionalParam');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['other' => 'value']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertArrayHasKey('testItOptionalParam', $this->page->testData);
		$this->assertNull($this->page->testData['testItOptionalParam']['id']);
	}

	public function testServerRequestInterfaceStillWorks(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testItWithServerRequest');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route, []);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertInstanceOf(ServerRequestInterface::class, $this->page->testData['testItWithServerRequest']['request']);
	}

	public function testMixedRouteParamsAndServerRequest(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testItWithBoth');
		$route = new Route('/test/{id}', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['id' => '100']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertSame(100, $this->page->testData['testItWithBoth']['id']);
		$this->assertInstanceOf(ServerRequestInterface::class, $this->page->testData['testItWithBoth']['request']);
	}

	public function testUntypedParameterGetsStringValue(): void
	{
		$this->page->resetTestData();

		$decorator = $this->createDecorator(TestPage::class . '::testItUntyped');
		$route = new Route('/test/{id}', $decorator);
		$routeResult = RouteResult::fromRoute($route, ['id' => '456']);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$handler = $this->createMock(RequestHandlerInterface::class);

		$middleware = new ExecutionMiddleware($this->container, $this->executor);
		$middleware->process($request, $handler);

		$this->assertSame('456', $this->page->testData['testItUntyped']['id']);
		$this->assertSame('string', $this->page->testData['testItUntyped']['type']);
	}

	protected function createDecorator(string $middlewareName): ActionMiddlewareDecorator
	{
		return new ActionMiddlewareDecorator($this->container, $middlewareName);
	}
}
