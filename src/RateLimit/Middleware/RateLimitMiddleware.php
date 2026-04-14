<?php

declare(strict_types=1);

namespace ON\RateLimit\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use ON\RateLimit\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected RateLimiterInterface $limiter,
		protected int $maxAttempts = 60,
		protected int $windowSeconds = 60,
		protected ?\Closure $keyResolver = null
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$key = $this->resolveKey($request);
		$result = $this->limiter->attempt($key, $this->maxAttempts, $this->windowSeconds);

		if (! $result->isAllowed()) {
			$response = new JsonResponse(
				['errors' => [['message' => 'Too many requests. Please try again later.']]],
				429
			);

			foreach ($result->getHeaders() as $name => $value) {
				$response = $response->withHeader($name, $value);
			}

			return $response;
		}

		$response = $handler->handle($request);

		// Add rate limit headers to successful responses
		foreach ($result->getHeaders() as $name => $value) {
			$response = $response->withHeader($name, $value);
		}

		return $response;
	}

	protected function resolveKey(ServerRequestInterface $request): string
	{
		if ($this->keyResolver !== null) {
			return ($this->keyResolver)($request);
		}

		// Default: rate limit by IP
		$serverParams = $request->getServerParams();

		return $serverParams['REMOTE_ADDR'] ?? 'unknown';
	}
}
