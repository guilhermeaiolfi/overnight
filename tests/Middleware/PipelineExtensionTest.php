<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\MiddlewarePipeInterface;
use ON\Application;
use ON\Event\EventsExtension;
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

	public function testProcessForwardCanCreateARequestFromGlobals(): void
	{
		$extension = new PipelineExtension($this->createStub(Application::class));
		$pipeline = $this->createMock(MiddlewarePipeInterface::class);
		$pipeline->expects($this->once())
			->method('handle')
			->with($this->callback(static function (ServerRequestInterface $request): bool {
				return $request->getUri()->getPath() === '/section' && $request->getMethod() === 'GET';
			}))
			->willReturn(new TextResponse('section'));
		$this->setProtectedProperty($extension, 'pipeline', $pipeline);

		$this->assertSame('section', (string) $extension->processForward('/section', method: 'GET')->getBody());
	}

	public function testRunInvokesTheRequestRunnerWithoutEventsInstalled(): void
	{
		$app = $this->createMock(Application::class);
		$app->expects($this->once())->method('hasExtension')->with(EventsExtension::class)->willReturn(false);
		$app->expects($this->never())->method('ext');
		$extension = new PipelineExtension($app);
		$runner = $this->createMock(RequestHandlerRunnerInterface::class);
		$runner->expects($this->once())->method('run');
		$this->setProtectedProperty($extension, 'runner', $runner);

		$extension->run();
	}

	public function testLoadPipelineCanBeInvokedTwiceInSameProcess(): void
	{
		$tempFile = tempnam(sys_get_temp_dir(), 'on_pipeline_');
		$this->assertNotFalse($tempFile);

		file_put_contents($tempFile, "<?php\nreturn static function (\\ON\\Application \$app, \\ON\\Middleware\\PipelineExtension \$pipeline): void {\n\t\$app->loadedPipeline = \$pipeline;\n};\n");

		try {
			$app1 = new class () extends Application {
				public ?PipelineExtension $loadedPipeline = null;

				public function __construct()
				{
				}
			};
			$app2 = new class () extends Application {
				public ?PipelineExtension $loadedPipeline = null;

				public function __construct()
				{
				}
			};

			$ext1 = new PipelineExtension($app1);
			$ext2 = new PipelineExtension($app2);

			$method = new ReflectionMethod(PipelineExtension::class, 'loadPipeline');
			$method->invoke($ext1, $tempFile);
			$method->invoke($ext2, $tempFile);

			$this->assertSame($ext1, $app1->loadedPipeline);
			$this->assertSame($ext2, $app2->loadedPipeline);
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
