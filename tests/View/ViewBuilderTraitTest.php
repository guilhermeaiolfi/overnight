<?php

declare(strict_types=1);

namespace Tests\ON\View;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Container\Executor\ExecutorInterface;
use ON\Router\RouterInterface;
use ON\Router\UrlHelper;
use ON\View\ViewConfig;
use ON\View\ViewManager;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ViewBuilderTraitTest extends TestCase
{
	public function testViewResultCallsViewMethodWithResult(): void
	{
		$page = new class {
			public ?ViewResult $receivedResult = null;

			public function successView(ViewResult $result)
			{
				$this->receivedResult = $result;
				return new HtmlResponse('<h1>Success</h1>');
			}
		};

		$response = $this->runView($page, 'create', new ViewResult('success', ['post' => ['id' => 1]]));

		$this->assertInstanceOf(HtmlResponse::class, $response);
		$this->assertSame(['post' => ['id' => 1]], $page->receivedResult->toArray());
	}

	public function testViewResultDataAccessibleViaGet(): void
	{
		$page = new class {
			public function successView(ViewResult $result)
			{
				return new HtmlResponse('post id: ' . $result->get('post')['id']);
			}
		};

		$response = $this->runView($page, 'create', new ViewResult('success', ['post' => ['id' => 42]]));

		$this->assertStringContainsString('post id: 42', (string) $response->getBody());
	}

	public function testViewResultDifferentViews(): void
	{
		$page = new class {
			public function successView(ViewResult $result)
			{
				return new HtmlResponse('success: ' . $result->get('message'));
			}

			public function errorView(ViewResult $result)
			{
				return new HtmlResponse('error: ' . $result->get('error'));
			}
		};

		$successResponse = $this->runView($page, 'create', new ViewResult('success', ['message' => 'Created!']));
		$errorResponse = $this->runView($page, 'create', new ViewResult('error', ['error' => 'Failed']));

		$this->assertStringContainsString('success: Created!', (string) $successResponse->getBody());
		$this->assertStringContainsString('error: Failed', (string) $errorResponse->getBody());
	}

	public function testStringReturnCreatesEmptyViewResult(): void
	{
		$page = new class {
			public ?ViewResult $receivedResult = null;

			public function successView(ViewResult $result)
			{
				$this->receivedResult = $result;
				return new HtmlResponse('ok');
			}
		};

		$this->runView($page, 'index', 'Success');

		$this->assertSame([], $page->receivedResult->toArray());
		$this->assertSame('success', $page->receivedResult->getViewName());
	}

	public function testResponseReturnedAsIs(): void
	{
		$jsonResponse = new JsonResponse(['status' => 'ok']);

		$response = $this->runView(new class {}, 'index', $jsonResponse);

		$this->assertSame($jsonResponse, $response);
	}

	public function testDefaultUrlHelperIsResolvedFromViewConfig(): void
	{
		$page = new class {
			public function successView(): HtmlResponse
			{
				return new HtmlResponse('ok');
			}
		};
		$executor = new class implements ExecutorInterface {
			public array $args = [];

			public function execute($callableOrMethodStr, array $args = [])
			{
				$this->args = $args;

				return $callableOrMethodStr();
			}

			public function getContainer(): ?ContainerInterface
			{
				return null;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$router = $this->createMock(RouterInterface::class);
		$container->method('has')
			->with(RouterInterface::class)
			->willReturn(true);
		$container->method('get')
			->with(RouterInterface::class)
			->willReturn($router);

		(new ViewManager(new ViewConfig(), $container))->runView(
			$page,
			'index',
			new ViewResult('success'),
			new ServerRequest(),
			$this->createMock(RequestHandlerInterface::class),
			$executor
		);

		$this->assertInstanceOf(UrlHelper::class, $executor->args[UrlHelper::class]);
	}

	public function testThrowsWhenViewMethodNotFound(): void
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('No view method found');

		$this->runView(new class {}, 'index', new ViewResult('nonexistent', []));
	}

	public function testViewResultArrayAccess(): void
	{
		$result = new ViewResult('success', ['title' => 'Hello', 'count' => 5]);

		$this->assertTrue(isset($result['title']));
		$this->assertFalse(isset($result['missing']));
		$this->assertSame('Hello', $result['title']);
		$this->assertSame(5, $result['count']);
		$this->assertNull($result['missing']);
	}

	public function testViewResultImmutable(): void
	{
		$result = new ViewResult('success', ['key' => 'value']);

		$this->expectException(\LogicException::class);
		$result['key'] = 'new';
	}

	public function testViewResultGetWithDefault(): void
	{
		$result = new ViewResult('success', ['name' => 'John']);

		$this->assertSame('John', $result->get('name'));
		$this->assertNull($result->get('missing'));
		$this->assertSame('fallback', $result->get('missing', 'fallback'));
	}

	public function testViewResultToArray(): void
	{
		$data = ['a' => 1, 'b' => 2];
		$result = new ViewResult('test', $data);

		$this->assertSame($data, $result->toArray());
	}

	private function runView(object $page, string $actionName, mixed $result): mixed
	{
		return $this->createViewManager()->runView(
			$page,
			$actionName,
			$result,
			new ServerRequest(),
			$this->createMock(RequestHandlerInterface::class),
			$this->createExecutor()
		);
	}

	private function createViewManager(): ViewManager
	{
		$router = $this->createMock(RouterInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')
			->willReturnCallback(fn(string $class): bool => $class === RouterInterface::class);
		$container->method('get')
			->willReturnCallback(fn(string $class): mixed => $class === RouterInterface::class ? $router : null);

		return new ViewManager(new ViewConfig(), $container);
	}

	private function createExecutor(): ExecutorInterface
	{
		return new class implements ExecutorInterface {
			public function execute($callableOrMethodStr, array $args = [])
			{
				return $callableOrMethodStr($args[ViewResult::class]);
			}

			public function getContainer(): ?ContainerInterface
			{
				return null;
			}
		};
	}
}
