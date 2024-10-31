<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Application;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OutputTypeMiddleware implements MiddlewareInterface
{
	public function __construct(
		private Application $app
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$accept = $request->getHeader('Accept')[0];
		$old_request = $request;
		if (! $accept || ! preg_match('#^application/([^+\s]+\+)?json#', $accept)) {
			$request = $request->withAttribute(OutputTypeMiddleware::class, "html");
		} else {
			$request = $request->withAttribute(OutputTypeMiddleware::class, "json");
		}

		$this->app->requestStack->update($old_request, $request);

		return $handler->handle($request);
	}
}
