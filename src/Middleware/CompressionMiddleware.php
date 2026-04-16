<?php

declare(strict_types=1);

namespace ON\Middleware;

use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gzip/deflate compression middleware.
 *
 * Compresses response bodies when the client supports it via Accept-Encoding.
 * Skips responses that are already encoded, too small to benefit, or binary.
 *
 * Usage:
 *   $app->pipe('/', new CompressionMiddleware(), 999); // high priority = runs last (wraps response)
 *
 * Or in extension config:
 *   'compression' => true
 */
class CompressionMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected int $minSize = 1024,
		protected int $level = 6
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);

		// Skip if already encoded
		if ($response->hasHeader('Content-Encoding')) {
			return $response;
		}

		// Check client support
		$acceptEncoding = $request->getHeaderLine('Accept-Encoding');
		if ($acceptEncoding === '') {
			return $response;
		}

		// Get response body
		$body = (string) $response->getBody();

		// Skip small responses
		if (strlen($body) < $this->minSize) {
			return $response;
		}

		// Skip binary content types
		$contentType = $response->getHeaderLine('Content-Type');
		if ($this->isBinary($contentType)) {
			return $response;
		}

		// Compress
		if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
			$compressed = gzencode($body, $this->level);
			if ($compressed !== false && strlen($compressed) < strlen($body)) {
				$factory = new StreamFactory();
				return $response
					->withBody($factory->createStream($compressed))
					->withHeader('Content-Encoding', 'gzip')
					->withHeader('Content-Length', (string) strlen($compressed))
					->withHeader('Vary', 'Accept-Encoding');
			}
		} elseif (str_contains($acceptEncoding, 'deflate') && function_exists('gzdeflate')) {
			$compressed = gzdeflate($body, $this->level);
			if ($compressed !== false && strlen($compressed) < strlen($body)) {
				$factory = new StreamFactory();
				return $response
					->withBody($factory->createStream($compressed))
					->withHeader('Content-Encoding', 'deflate')
					->withHeader('Content-Length', (string) strlen($compressed))
					->withHeader('Vary', 'Accept-Encoding');
			}
		}

		return $response;
	}

	protected function isBinary(string $contentType): bool
	{
		$binaryTypes = ['image/', 'audio/', 'video/', 'application/zip', 'application/gzip', 'application/octet-stream'];

		foreach ($binaryTypes as $type) {
			if (str_contains($contentType, $type)) {
				return true;
			}
		}

		return false;
	}
}
