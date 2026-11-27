<?php

declare(strict_types=1);

namespace Tests\ON\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use ON\Handler\NotFoundHandler;
use ON\Middleware\OutputTypeMiddleware;
use ON\Middleware\RequestForwarderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class NotFoundHandlerTest extends TestCase
{
	public function testForwardsHtmlRequestsToConfigured404Path(): void
	{
		$forwarded = new HtmlResponse('custom 404');
		$forwarder = new class ($forwarded) implements RequestForwarderInterface {
			public ?string $forwardedMethod = null;

			public function __construct(private ResponseInterface $response)
			{
			}

			public function processForward(string $path, ?ServerRequestInterface $request = null, ?string $method = null): ResponseInterface
			{
				$this->forwardedMethod = $method;

				return $this->response;
			}
		};

		$handler = new NotFoundHandler($forwarder, '/404', new ResponseFactory());
		$request = (new ServerRequest())
			->withMethod('POST')
			->withUri(new Uri('/missing'))
			->withAttribute(OutputTypeMiddleware::class, 'html');

		$response = $handler->handle($request);

		$this->assertSame('custom 404', (string) $response->getBody());
		$this->assertSame('GET', $forwarder->forwardedMethod);
	}

	public function testDoesNotForwardAgainWhenAlreadyOn404Path(): void
	{
		$forwarder = new class () implements RequestForwarderInterface {
			public function processForward(string $path, ?ServerRequestInterface $request = null, ?string $method = null): ResponseInterface
			{
				throw new RuntimeException('Should not forward recursively.');
			}
		};

		$handler = new NotFoundHandler($forwarder, '/404', new ResponseFactory());
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
