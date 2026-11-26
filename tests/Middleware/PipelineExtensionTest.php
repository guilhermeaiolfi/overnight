<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Stratigility\MiddlewarePipeInterface;
use ON\Application;
use ON\Middleware\PipelineExtension;
use ON\Middleware\RequestPreparerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class PipelineExtensionTest extends TestCase
{
	public function testHandleRunsRegisteredRequestPreparersInPriorityOrder(): void
	{
		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$extension = new PipelineExtension($app);

		$pipeline = $this->createMock(MiddlewarePipeInterface::class);
		$pipeline->expects($this->once())
			->method('handle')
			->with($this->callback(function (ServerRequestInterface $request): bool {
				return $request->getAttribute('order') === ['high', 'low'];
			}))
			->willReturn(new TextResponse('ok'));

		$this->setProtectedProperty($extension, 'pipeline', $pipeline);

		$extension->addRequestPreparer(new class () implements RequestPreparerInterface {
			public function prepare(ServerRequestInterface $request): ServerRequestInterface
			{
				$order = $request->getAttribute('order', []);
				$order[] = 'low';

				return $request->withAttribute('order', $order);
			}
		}, 10);

		$extension->addRequestPreparer(new class () implements RequestPreparerInterface {
			public function prepare(ServerRequestInterface $request): ServerRequestInterface
			{
				$order = $request->getAttribute('order', []);
				$order[] = 'high';

				return $request->withAttribute('order', $order);
			}
		}, 100);

		$response = $extension->handle(new ServerRequest());

		$this->assertSame('ok', (string) $response->getBody());
	}

	public function testProcessRunsRequestPreparersBeforeDispatch(): void
	{
		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$extension = new PipelineExtension($app);

		$pipeline = $this->createMock(MiddlewarePipeInterface::class);
		$pipeline->expects($this->once())
			->method('process')
			->with(
				$this->callback(fn (ServerRequestInterface $request): bool => $request->getAttribute('prepared') === true),
				$this->isInstanceOf(RequestHandlerInterface::class)
			)
			->willReturn(new TextResponse('processed'));

		$this->setProtectedProperty($extension, 'pipeline', $pipeline);

		$extension->addRequestPreparer(new class () implements RequestPreparerInterface {
			public function prepare(ServerRequestInterface $request): ServerRequestInterface
			{
				return $request->withAttribute('prepared', true);
			}
		});

		$handler = $this->createMock(RequestHandlerInterface::class);
		$response = $extension->process(new ServerRequest(), $handler);

		$this->assertSame('processed', (string) $response->getBody());
	}

	public function testProcessForwardEnforcesMaxDepthLimit(): void
	{
		$app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
		$extension = new PipelineExtension($app);

		$pipeline = $this->createMock(MiddlewarePipeInterface::class);
		$pipeline->method('handle')
			->willReturnCallback(function (ServerRequestInterface $request) use ($extension): ResponseInterface {
				return $extension->processForward('/loop', $request, 'GET');
			});

		$this->setProtectedProperty($extension, 'pipeline', $pipeline);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Maximum forward depth');

		$extension->processForward('/loop', new ServerRequest(), 'GET');
	}

	public function testLoadPipelineCanBeInvokedTwiceInSameProcess(): void
	{
		$tempFile = tempnam(sys_get_temp_dir(), 'on_pipeline_');
		$this->assertNotFalse($tempFile);

		file_put_contents($tempFile, "<?php\nreturn static function (\\ON\\Application \$app): void {\n\t\$app->loadedCount++;\n};\n");

		try {
			$app1 = new class () extends Application {
				public int $loadedCount = 0;

				public function __construct()
				{
				}
			};
			$app2 = new class () extends Application {
				public int $loadedCount = 0;

				public function __construct()
				{
				}
			};

			$ext1 = new PipelineExtension($app1);
			$ext2 = new PipelineExtension($app2);

			$method = new ReflectionMethod(PipelineExtension::class, 'loadPipeline');
			$method->invoke($ext1, $tempFile);
			$method->invoke($ext2, $tempFile);

			$this->assertSame(1, $app1->loadedCount);
			$this->assertSame(1, $app2->loadedCount);
		} finally {
			@unlink($tempFile);
		}
	}

	/**
	 * @param object $target
	 */
	private function setProtectedProperty(object $target, string $property, mixed $value): void
	{
		$reflection = new ReflectionProperty($target, $property);
		$reflection->setValue($target, $value);
	}
}
