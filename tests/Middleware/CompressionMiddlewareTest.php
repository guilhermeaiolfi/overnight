<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Middleware\CompressionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class CompressionMiddlewareTest extends TestCase
{
	private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
	{
		return new class ($response) implements RequestHandlerInterface {
			public function __construct(private ResponseInterface $response)
			{
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return $this->response;
			}
		};
	}

	public function testCompressesTextResponseWithGzipAndAddsVaryHeader(): void
	{
		$payload = str_repeat('Hello compressible response body! ', 100);
		$response = new TextResponse($payload);
		$request = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip, deflate');

		$middleware = new CompressionMiddleware(minSize: 128);
		$result = $middleware->process($request, $this->handlerReturning($response));

		$this->assertSame('gzip', $result->getHeaderLine('Content-Encoding'));
		$this->assertSame('Accept-Encoding', $result->getHeaderLine('Vary'));
		$this->assertSame((string) strlen((string) $result->getBody()), $result->getHeaderLine('Content-Length'));
		$this->assertSame($payload, gzdecode((string) $result->getBody()));
	}

	public function testPreservesExistingVaryHeaderWithoutOverwritingOrDuplicating(): void
	{
		$payload = str_repeat('Vary preservation test content. ', 100);
		$middleware = new CompressionMiddleware(minSize: 128);
		$request = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip');

		$withOrigin = (new TextResponse($payload))->withHeader('Vary', 'Origin');
		$resultWithOrigin = $middleware->process($request, $this->handlerReturning($withOrigin));

		$this->assertSame(['Origin', 'Accept-Encoding'], $resultWithOrigin->getHeader('Vary'));

		$withAcceptEncoding = (new TextResponse($payload))->withHeader('Vary', 'Origin, accept-encoding');
		$resultNoDuplicate = $middleware->process($request, $this->handlerReturning($withAcceptEncoding));

		$this->assertSame(['Origin, accept-encoding'], $resultNoDuplicate->getHeader('Vary'));
	}

	public function testRespectsAcceptEncodingQualityValuesAndQZero(): void
	{
		$payload = str_repeat('Quality value negotiation test payload. ', 100);
		$middleware = new CompressionMiddleware(minSize: 128);

		// gzip explicitly rejected with q=0, deflate accepted
		$reqDeflate = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip;q=0, deflate');
		$resDeflate = $middleware->process($reqDeflate, $this->handlerReturning(new TextResponse($payload)));
		$this->assertSame('deflate', $resDeflate->getHeaderLine('Content-Encoding'));
		$this->assertSame($payload, gzinflate((string) $resDeflate->getBody()));

		// both gzip and deflate rejected with q=0
		$reqNone = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip;q=0, deflate;q=0');
		$resNone = $middleware->process($reqNone, $this->handlerReturning(new TextResponse($payload)));
		$this->assertFalse($resNone->hasHeader('Content-Encoding'));
		$this->assertSame($payload, (string) $resNone->getBody());

		// higher quality weight on deflate than gzip
		$reqHigherDeflate = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip;q=0.2, deflate;q=0.9');
		$resHigherDeflate = $middleware->process($reqHigherDeflate, $this->handlerReturning(new TextResponse($payload)));
		$this->assertSame('deflate', $resHigherDeflate->getHeaderLine('Content-Encoding'));

		// wildcard rejected
		$reqWildcardZero = (new ServerRequest())->withHeader('Accept-Encoding', '*;q=0');
		$resWildcardZero = $middleware->process($reqWildcardZero, $this->handlerReturning(new TextResponse($payload)));
		$this->assertFalse($resWildcardZero->hasHeader('Content-Encoding'));

		// gzip rejected but wildcard accepts deflate
		$reqGzipZeroWildcard = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip;q=0, *');
		$resGzipZeroWildcard = $middleware->process($reqGzipZeroWildcard, $this->handlerReturning(new TextResponse($payload)));
		$this->assertSame('deflate', $resGzipZeroWildcard->getHeaderLine('Content-Encoding'));
	}

	public function testSkipsBinaryContentTypeBeforeReadingBodyStream(): void
	{
		$unreadableStream = new class () implements StreamInterface {
			public bool $wasRead = false;

			public function __toString(): string
			{
				$this->wasRead = true;

				throw new RuntimeException('Binary stream body must not be buffered into memory.');
			}

			public function close(): void
			{
			}

			public function detach()
			{
				return null;
			}

			public function getSize(): ?int
			{
				return 100_000_000;
			}

			public function tell(): int
			{
				return 0;
			}

			public function eof(): bool
			{
				return false;
			}

			public function isSeekable(): bool
			{
				return false;
			}

			public function seek(int $offset, int $whence = SEEK_SET): void
			{
			}

			public function rewind(): void
			{
			}

			public function isWritable(): bool
			{
				return false;
			}

			public function write(string $string): int
			{
				return 0;
			}

			public function isReadable(): bool
			{
				return true;
			}

			public function read(int $length): string
			{
				$this->wasRead = true;

				throw new RuntimeException('Binary stream must not be read.');
			}

			public function getContents(): string
			{
				$this->wasRead = true;

				throw new RuntimeException('Binary stream must not be read.');
			}

			public function getMetadata(?string $key = null)
			{
				return null;
			}
		};

		$response = (new Response($unreadableStream, 200, ['Content-Type' => 'image/png']));
		$request = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip, deflate');

		$middleware = new CompressionMiddleware(minSize: 128);
		$result = $middleware->process($request, $this->handlerReturning($response));

		$this->assertSame($response, $result);
		$this->assertFalse($unreadableStream->wasRead);
		$this->assertFalse($result->hasHeader('Content-Encoding'));
	}

	public function testSkipsAlreadyEncodedAndSmallResponses(): void
	{
		$payload = str_repeat('Already encoded content. ', 100);
		$middleware = new CompressionMiddleware(minSize: 1024);
		$request = (new ServerRequest())->withHeader('Accept-Encoding', 'gzip');

		$alreadyEncoded = (new TextResponse($payload))->withHeader('Content-Encoding', 'br');
		$resEncoded = $middleware->process($request, $this->handlerReturning($alreadyEncoded));
		$this->assertSame('br', $resEncoded->getHeaderLine('Content-Encoding'));

		$small = new TextResponse('too small');
		$resSmall = $middleware->process($request, $this->handlerReturning($small));
		$this->assertFalse($resSmall->hasHeader('Content-Encoding'));
		$this->assertSame('too small', (string) $resSmall->getBody());
	}
}
