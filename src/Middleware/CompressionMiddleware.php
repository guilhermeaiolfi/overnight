<?php

declare(strict_types=1);

namespace ON\Middleware;

use function array_key_first;
use function count;
use function explode;
use function function_exists;
use function gzdeflate;
use function gzencode;
use function is_numeric;
use Laminas\Diactoros\StreamFactory;
use function preg_match;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * Gzip/deflate compression middleware.
 *
 * Compresses response bodies when the client supports it via Accept-Encoding.
 * Skips responses that are already encoded, too small to benefit, or binary.
 *
 * Usage:
 *   $pipeline->pipe('/', new CompressionMiddleware(), 999); // high priority = runs last (wraps response)
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

		// Skip binary content types before buffering the body into memory
		$contentType = $response->getHeaderLine('Content-Type');
		if ($this->isBinary($contentType)) {
			return $response;
		}

		// Negotiate encoding before reading the body
		$encoding = $this->negotiateEncoding($request->getHeaderLine('Accept-Encoding'));
		if ($encoding === null) {
			return $response;
		}

		// Skip small responses without reading when stream size is known
		$streamSize = $response->getBody()->getSize();
		if ($streamSize !== null && $streamSize < $this->minSize) {
			return $response;
		}

		// Get response body
		$body = (string) $response->getBody();

		// Skip small responses
		if (strlen($body) < $this->minSize) {
			return $response;
		}

		$compressed = $encoding === 'gzip'
			? gzencode($body, $this->level)
			: gzdeflate($body, $this->level);

		if ($compressed !== false && strlen($compressed) < strlen($body)) {
			$factory = new StreamFactory();

			$compressedResponse = $response
				->withBody($factory->createStream($compressed))
				->withHeader('Content-Encoding', $encoding)
				->withHeader('Content-Length', (string) strlen($compressed));

			return $this->addVaryHeader($compressedResponse);
		}

		return $response;
	}

	protected function negotiateEncoding(string $acceptEncoding): ?string
	{
		if (trim($acceptEncoding) === '') {
			return null;
		}

		$qualities = [];
		$wildcardQuality = null;

		foreach (explode(',', $acceptEncoding) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}

			$segments = explode(';', $part);
			$encoding = strtolower(trim($segments[0]));
			if ($encoding === '') {
				continue;
			}

			$quality = 1.0;
			for ($i = 1, $count = count($segments); $i < $count; $i++) {
				$param = trim($segments[$i]);
				if (preg_match('/^q\s*=\s*(.+)$/i', $param, $matches) === 1) {
					$rawQ = trim($matches[1]);
					if (is_numeric($rawQ)) {
						$parsed = (float) $rawQ;
						$quality = ($parsed >= 0.0 && $parsed <= 1.0) ? $parsed : 0.0;
					} else {
						$quality = 0.0;
					}

					break;
				}
			}

			if ($encoding === '*') {
				$wildcardQuality = $quality;
			} else {
				$qualities[$encoding] = $quality;
			}
		}

		$candidates = [];
		if (function_exists('gzencode')) {
			$gzipQ = $qualities['gzip'] ?? $wildcardQuality ?? 0.0;
			if ($gzipQ > 0.0) {
				$candidates['gzip'] = $gzipQ;
			}
		}

		if (function_exists('gzdeflate')) {
			$deflateQ = $qualities['deflate'] ?? $wildcardQuality ?? 0.0;
			if ($deflateQ > 0.0) {
				$candidates['deflate'] = $deflateQ;
			}
		}

		if ($candidates === []) {
			return null;
		}

		if (isset($candidates['gzip'], $candidates['deflate'])) {
			return $candidates['deflate'] > $candidates['gzip'] ? 'deflate' : 'gzip';
		}

		return array_key_first($candidates);
	}

	protected function addVaryHeader(ResponseInterface $response): ResponseInterface
	{
		foreach ($response->getHeader('Vary') as $headerValue) {
			foreach (explode(',', $headerValue) as $token) {
				if (strtolower(trim($token)) === 'accept-encoding') {
					return $response;
				}
			}
		}

		return $response->withAddedHeader('Vary', 'Accept-Encoding');
	}

	protected function isBinary(string $contentType): bool
	{
		$contentType = strtolower($contentType);
		$binaryTypes = ['image/', 'audio/', 'video/', 'application/zip', 'application/gzip', 'application/octet-stream'];

		foreach ($binaryTypes as $type) {
			if (str_contains($contentType, $type)) {
				return true;
			}
		}

		return false;
	}
}
