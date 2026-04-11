<?php

declare(strict_types=1);

namespace Tests\ON\Router;

use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use ON\Container\Executor\Executor;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\Route;
use ON\Router\RouteResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\Fixtures\TestPage;

final class ActionMiddlewareDecoratorTest extends TestCase
{
	private ContainerInterface $container;
	private Executor $executor;
	private TestPage $page;
	private \ON\Application $app;

	protected function setUp(): void
	{
		$this->page = new TestPage();
		$this->app = $this->createMock(\ON\Application::class);
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
				if ($class === \ON\Application::class) {
					return $this->app;
				}
				if ($class === Executor::class || $class === ExecutorInterface::class) {
					return $this->executor;
				}
				if ($class === TestPage::class) {
					return $this->page;
				}
				return null;
			});
	}

	public function testRouteParamsAreInjectedAsMethodArguments(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['id' => '42', 'name' => 'test']
		);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testIt'
		);

		$decorator->execute($request, $handler);

		$this->assertSame(42, $this->page->testData['testIt']['id']);
		$this->assertSame('test', $this->page->testData['testIt']['name']);
	}

	public function testTypeCastingForIntegers(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['id' => '123']
		);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testInt'
		);

		$decorator->execute($request, $handler);

		$this->assertSame(123, $this->page->testData['testInt']['id']);
		$this->assertSame('integer', $this->page->testData['testInt']['type']);
	}

	public function testTypeCastingForFloats(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['price' => '19.99']
		);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testFloat'
		);

		$decorator->execute($request, $handler);

		$this->assertEqualsWithDelta(19.99, $this->page->testData['testFloat']['price'], 0.001);
		$this->assertSame('double', $this->page->testData['testFloat']['type']);
	}

	public function testTypeCastingForBooleans(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['active' => '1']
		);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testBool'
		);

		$decorator->execute($request, $handler);

		$this->assertTrue($this->page->testData['testBool']['active']);
		$this->assertSame('boolean', $this->page->testData['testBool']['type']);
	}

	public function testOptionalParamNotProvidedGetsNull(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['other' => 'value']
		);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testItOptionalParam'
		);

		$decorator->execute($request, $handler);

		$this->assertArrayHasKey('testItOptionalParam', $this->page->testData);
		$this->assertNull($this->page->testData['testItOptionalParam']['id']);
	}

	public function testServerRequestInterfaceStillWorks(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			[]
		);

		$mockRequest = $this->createMock(ServerRequestInterface::class);
		$mockRequest->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testItWithServerRequest'
		);

		$decorator->execute($mockRequest, $handler);

		$this->assertInstanceOf(ServerRequestInterface::class, $this->page->testData['testItWithServerRequest']['request']);
	}

	public function testMixedRouteParamsAndServerRequest(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['id' => '100']
		);

		$mockRequest = $this->createMock(ServerRequestInterface::class);
		$mockRequest->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testItWithBoth'
		);

		$decorator->execute($mockRequest, $handler);

		$this->assertSame(100, $this->page->testData['testItWithBoth']['id']);
		$this->assertInstanceOf(ServerRequestInterface::class, $this->page->testData['testItWithBoth']['request']);
	}

	public function testUntypedParameterGetsStringValue(): void
	{
		$this->page->resetTestData();

		$routeResult = RouteResult::fromRoute(
			$this->createMock(Route::class),
			['id' => '456']
		);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnCallback(function (string $name) use ($routeResult) {
				if ($name === RouteResult::class) {
					return $routeResult;
				}
				return null;
			});

		$handler = $this->createMock(RequestHandlerInterface::class);

		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testItUntyped'
		);

		$decorator->execute($request, $handler);

		$this->assertSame('456', $this->page->testData['testItUntyped']['id']);
		$this->assertSame('string', $this->page->testData['testItUntyped']['type']);
	}
}
