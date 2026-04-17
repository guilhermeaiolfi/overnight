<?php

declare(strict_types=1);

namespace Tests\ON\View;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use ON\View\ViewBuilderTrait;
use ON\View\ViewInterface;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;

final class ViewBuilderTraitTest extends TestCase
{
	public function testViewResultCallsViewMethodWithResult(): void
	{
		$builder = $this->createBuilder();

		$page = new class {
			public ?ViewResult $receivedResult = null;

			public function successView(ViewResult $result, $request = null, $delegate = null)
			{
				$this->receivedResult = $result;
				return new HtmlResponse('<h1>Success</h1>');
			}
		};

		$result = new ViewResult('success', ['post' => ['id' => 1]]);

		$response = $builder->buildView($page, 'create', $result, null, null);

		$this->assertInstanceOf(HtmlResponse::class, $response);
		$this->assertSame(['post' => ['id' => 1]], $page->receivedResult->data);
	}

	public function testViewResultDataAccessibleViaGet(): void
	{
		$builder = $this->createBuilder();

		$page = new class {
			public function successView(ViewResult $result, $request = null, $delegate = null)
			{
				return new HtmlResponse('post id: ' . $result->get('post')['id']);
			}
		};

		$result = new ViewResult('success', ['post' => ['id' => 42]]);
		$response = $builder->buildView($page, 'create', $result, null, null);

		$this->assertStringContainsString('post id: 42', (string) $response->getBody());
	}

	public function testViewResultDifferentViews(): void
	{
		$builder = $this->createBuilder();

		$page = new class {
			public function successView(ViewResult $result, $request = null, $delegate = null)
			{
				return new HtmlResponse('success: ' . $result->get('message'));
			}

			public function errorView(ViewResult $result, $request = null, $delegate = null)
			{
				return new HtmlResponse('error: ' . $result->get('error'));
			}
		};

		$successResponse = $builder->buildView($page, 'create', new ViewResult('success', ['message' => 'Created!']), null, null);
		$errorResponse = $builder->buildView($page, 'create', new ViewResult('error', ['error' => 'Failed']), null, null);

		$this->assertStringContainsString('success: Created!', (string) $successResponse->getBody());
		$this->assertStringContainsString('error: Failed', (string) $errorResponse->getBody());
	}

	public function testStringReturnCreatesEmptyViewResult(): void
	{
		$builder = $this->createBuilder();

		$page = new class {
			public ?ViewResult $receivedResult = null;

			public function successView(ViewResult $result, $request = null, $delegate = null)
			{
				$this->receivedResult = $result;
				return new HtmlResponse('ok');
			}
		};

		$builder->buildView($page, 'index', 'Success', null, null);

		$this->assertSame([], $page->receivedResult->data);
		$this->assertSame('Success', $page->receivedResult->view);
	}

	public function testResponseReturnedAsIs(): void
	{
		$builder = $this->createBuilder();
		$page = new class {};

		$jsonResponse = new JsonResponse(['status' => 'ok']);

		$response = $builder->buildView($page, 'index', $jsonResponse, null, null);

		$this->assertSame($jsonResponse, $response);
	}

	public function testSetsDefaultTemplateNameOnView(): void
	{
		$builder = $this->createBuilder();

		$mockView = $this->createMock(ViewInterface::class);
		$mockView->expects($this->once())
			->method('setDefaultTemplateName')
			->with($this->stringContains('success'));

		// Property named 'renderer' — not 'view' — to prove reflection finds it by type
		$page = new class($mockView) {
			public ViewInterface $renderer;

			public function __construct(ViewInterface $renderer)
			{
				$this->renderer = $renderer;
			}

			public function successView(ViewResult $result, $request = null, $delegate = null)
			{
				return new HtmlResponse('ok');
			}
		};

		$result = new ViewResult('success', []);
		$builder->buildView($page, 'index', $result, null, null);
	}

	public function testThrowsWhenViewMethodNotFound(): void
	{
		$builder = $this->createBuilder();
		$page = new class {};

		$result = new ViewResult('nonexistent', []);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('No view method found');

		$builder->buildView($page, 'index', $result, null, null);
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

	protected function createBuilder(): object
	{
		return new class {
			use ViewBuilderTrait;

			public $container;
			public $executor;

			public function __construct()
			{
				$this->container = null;
				$this->executor = null;
			}
		};
	}
}
