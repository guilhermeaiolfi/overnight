<?php

declare(strict_types=1);

namespace ON\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OutputTypeMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$accept = $request->getHeaderLine('Accept');

		if (! $accept || ! preg_match('#^application/([^+\s]+\+)?json#', $accept)) {
			$request = $request->withAttribute(OutputTypeMiddleware::class, "html");
		} else {
			$request = $request->withAttribute(OutputTypeMiddleware::class, "json");
		}

		return $handler->handle($request);
	}
}
