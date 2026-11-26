<?php

declare(strict_types=1);

namespace ON\Router\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use ON\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Handles method failures before middleware expects a resolved action. */
final class MethodNotAllowedMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$result = $request->getAttribute(RouteResult::class);
		if ($result instanceof RouteResult && $result->isMethodFailure()) {
			return new EmptyResponse(405, ['Allow' => implode(', ', $result->getAllowedMethods() ?? [])]);
		}

		return $handler->handle($request);
	}
}
