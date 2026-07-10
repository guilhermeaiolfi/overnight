<?php

declare(strict_types=1);

namespace Tests\ON\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use ON\Application;
use ON\Handler\NotFoundHandler;
use ON\Middleware\OutputTypeMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class NotFoundHandlerTest extends TestCase
{
	public function testForwardsHtmlRequestsToConfigured404Path(): void
	{
		$forwarded = new HtmlResponse('custom 404');
		$app = new class ($forwarded) extends Application {
			public function __construct(private ResponseInterface $response)
			{
			}

			public function processForward($path, $request, $method = null): ResponseInterface
			{
				return $this->response;
			}
		};

		$handler = new NotFoundHandler($app, '/404', new ResponseFactory());
		$request = (new ServerRequest())
			->withUri(new Uri('/missing'))
			->withAttribute(OutputTypeMiddleware::class, 'html');

		$response = $handler->handle($request);

		$this->assertSame('custom 404', (string) $response->getBody());
	}

	public function testDoesNotForwardAgainWhenAlreadyOn404Path(): void
	{
		$app = new class () extends Application {
			public function __construct()
			{
			}

			public function processForward($path, $request, $method = null): ResponseInterface
			{
				throw new RuntimeException('Should not forward recursively.');
			}
		};

		$handler = new NotFoundHandler($app, '/404', new ResponseFactory());
		$request = (new ServerRequest())
			->withUri(new Uri('/404'))
			->withMethod('GET')
			->withAttribute(OutputTypeMiddleware::class, 'html');

		$response = $handler->handle($request);

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame('Cannot GET /404', (string) $response->getBody());
	}
}
