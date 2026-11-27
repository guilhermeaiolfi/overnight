<?php

declare(strict_types=1);

namespace ON\DB\Middleware;

use ON\DB\DatabaseStoreInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DatabaseScopeMiddleware implements MiddlewareInterface
{
	public function __construct(
		private DatabaseStoreInterface $store,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->store->scoped(
			static fn (): ResponseInterface => $handler->handle($request)
		);
	}
}
