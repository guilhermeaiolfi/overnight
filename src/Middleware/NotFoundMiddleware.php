<?php

declare(strict_types=1);

namespace ON\Middleware;

use Laminas\Stratigility\Exception\EmptyPipelineException;
use ON\Handler\NotFoundHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class NotFoundMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected NotFoundHandler $notFoundHandler
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		try {
			$response = $handler->handle($request, $handler);

			return $response;
		} catch (EmptyPipelineException $e) {
			return $this->notFoundHandler->handle($request, $handler);
		}
	}
}
