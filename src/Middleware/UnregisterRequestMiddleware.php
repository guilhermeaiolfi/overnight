<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\RequestStack;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UnregisterRequestMiddleware implements MiddlewareInterface
{
	public function __construct(protected RequestStack $stack)
	{
	}

	public function process(ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
	{
		$this->stack->pop();

		return $handler->handle($request);
	}
}
