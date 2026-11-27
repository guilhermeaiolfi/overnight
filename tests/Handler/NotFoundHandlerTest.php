<?php

declare(strict_types=1);

namespace Tests\ON\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use ON\Handler\NotFoundHandler;
use ON\Middleware\OutputTypeMiddleware;
use ON\Middleware\PipelineExtension;
use PHPUnit\Framework\TestCase;

final class NotFoundHandlerTest extends TestCase
{
	public function testForwardsHtmlRequestsToConfigured404Path(): void
	{
		$forwarded = new HtmlResponse('custom 404');
		$pipeline = $this->createMock(PipelineExtension::class);

		$handler = new NotFoundHandler($pipeline, '/404', new ResponseFactory());
		$request = (new ServerRequest())
			->withMethod('POST')
			->withUri(new Uri('/missing'))
			->withAttribute(OutputTypeMiddleware::class, 'html');

		$pipeline->expects($this->once())->method('processForward')
			->with('/404', $request, 'GET')
			->willReturn($forwarded);

		$response = $handler->handle($request);

		$this->assertSame('custom 404', (string) $response->getBody());
	}

	public function testDoesNotForwardAgainWhenAlreadyOn404Path(): void
	{
		$pipeline = $this->createMock(PipelineExtension::class);
		$pipeline->expects($this->never())->method('processForward');

		$handler = new NotFoundHandler($pipeline, '/404', new ResponseFactory());
		$request = (new ServerRequest())
			->withUri(new Uri('/404'))
			->withMethod('GET')
			->withAttribute(OutputTypeMiddleware::class, 'html');

		$response = $handler->handle($request);

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame('Cannot GET /404', (string) $response->getBody());
		$this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
	}
}
