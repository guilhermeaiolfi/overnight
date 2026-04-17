<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Container\Executor\ExecutorInterface;
use ON\Middleware\ValidationMiddleware;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\Route;
use ON\Router\RouteResult;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ValidationMiddlewareTest extends TestCase
{
	public function testPassesThroughWhenNoRouteResult(): void
	{
		$executor = $this->createMock(ExecutorInterface::class);
		$middleware = new ValidationMiddleware($executor);

		$request = new ServerRequest();
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testPassesThroughWhenMiddlewareIsNotActionDecorator(): void
	{
		$executor = $this->createMock(ExecutorInterface::class);
		$middleware = new ValidationMiddleware($executor);

		$route = new Route('/test', $this->createMock(\Psr\Http\Server\MiddlewareInterface::class));
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testPassesThroughWhenNoValidateMethod(): void
	{
		$page = new class {
			public function index(): string
			{
				return 'Success';
			}
		};

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->never())->method('execute');

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'index');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testCallsActionSpecificValidateMethod(): void
	{
		$page = new class {
			public bool $validateCalled = false;

			public function createValidate(): bool
			{
				$this->validateCalled = true;
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
				$this->callback(fn($args) => isset($args[ServerRequestInterface::class]))
			)
			->willReturn(true);

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'create');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('ok');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testFallsBackToGenericValidateMethod(): void
	{
		$page = new class {
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
			->with(
				[$page, 'validate'],
				$this->anything()
			)
			->willReturn(true);

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'index');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('ok'));

		$middleware->process($request, $handler);
	}

	public function testFallsBackToDefaultValidateMethod(): void
	{
		$page = new class {
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
			->with(
				[$page, 'defaultValidate'],
				$this->anything()
			)
			->willReturn(true);

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'index');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('ok'));

		$middleware->process($request, $handler);
	}

	public function testCallsErrorHandlerOnValidationFailure(): void
	{
		$page = new class {
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

		$errorResponse = new HtmlResponse('validation error');

		$executor = $this->createMock(ExecutorInterface::class);
		$executor->expects($this->exactly(2))
			->method('execute')
			->willReturnCallback(function ($callable, $args) use ($page) {
				if ($callable[1] === 'validate') {
					return false;
				}
				if ($callable[1] === 'handleError') {
					return 'Error';
				}
				return null;
			});

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'index');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('should not reach'));

		$response = $middleware->process($request, $handler);

		// buildView is called on the decorator, which processes the error response
		$this->assertInstanceOf(ResponseInterface::class, $response);
	}

	public function testPassesThroughWhenValidationFailsButNoErrorHandler(): void
	{
		$page = new class {
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

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'index');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);
		$expectedResponse = new TextResponse('fallthrough');

		$handler = $this->createHandler($expectedResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame($expectedResponse, $response);
	}

	public function testPrefersActionSpecificOverGenericValidate(): void
	{
		$page = new class {
			public function createValidate(): bool
			{
				return true;
			}

			public function validate(): bool
			{
				return false; // should NOT be called
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
				$this->anything()
			)
			->willReturn(true);

		$middleware = new ValidationMiddleware($executor);

		$decorator = $this->createActionDecorator($page, 'create');
		$route = new Route('/test', $decorator);
		$routeResult = RouteResult::fromRoute($route);

		$request = (new ServerRequest())->withAttribute(RouteResult::class, $routeResult);

		$handler = $this->createHandler(new TextResponse('ok'));

		$middleware->process($request, $handler);
	}

	protected function createHandler(ResponseInterface $response): RequestHandlerInterface
	{
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($response);
		return $handler;
	}

	protected function createActionDecorator(object $page, string $method): ActionMiddlewareDecorator
	{
		// Use reflection to set the internal state without going through the constructor
		$ref = new \ReflectionClass(ActionMiddlewareDecorator::class);
		$decorator = $ref->newInstanceWithoutConstructor();

		$instanceProp = $ref->getProperty('instance');
		$instanceProp->setAccessible(true);
		$instanceProp->setValue($decorator, $page);

		$methodProp = $ref->getProperty('method');
		$methodProp->setAccessible(true);
		$methodProp->setValue($decorator, $method);

		return $decorator;
	}
}
