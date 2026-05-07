<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Middleware\PipelineExtension;
use ON\Middleware\RequestPreparerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Stratigility\MiddlewarePipeInterface;

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

		$extension->addRequestPreparer(new class implements RequestPreparerInterface {
			public function prepare(ServerRequestInterface $request): ServerRequestInterface
			{
				$order = $request->getAttribute('order', []);
				$order[] = 'low';

				return $request->withAttribute('order', $order);
			}
		}, 10);

		$extension->addRequestPreparer(new class implements RequestPreparerInterface {
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
				$this->callback(fn(ServerRequestInterface $request): bool => $request->getAttribute('prepared') === true),
				$this->isInstanceOf(RequestHandlerInterface::class)
			)
			->willReturn(new TextResponse('processed'));

		$this->setProtectedProperty($extension, 'pipeline', $pipeline);

		$extension->addRequestPreparer(new class implements RequestPreparerInterface {
			public function prepare(ServerRequestInterface $request): ServerRequestInterface
			{
				return $request->withAttribute('prepared', true);
			}
		});

		$handler = $this->createMock(RequestHandlerInterface::class);
		$response = $extension->process(new ServerRequest(), $handler);

		$this->assertSame('processed', (string) $response->getBody());
	}

	/**
	 * @param object $target
	 */
	private function setProtectedProperty(object $target, string $property, mixed $value): void
	{
		$reflection = new \ReflectionProperty($target, $property);
		$reflection->setValue($target, $value);
	}
}
