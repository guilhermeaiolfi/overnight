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
	private const SCOPED_ATTRIBUTE = self::class . ':scoped';

	public function __construct(
		private DatabaseStoreInterface $store,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($request->getAttribute(self::SCOPED_ATTRIBUTE) === true) {
			return $handler->handle($request);
		}

		try {
			return $handler->handle($request->withAttribute(self::SCOPED_ATTRIBUTE, true));
		} finally {
			$this->store->close();
		}
	}
}
