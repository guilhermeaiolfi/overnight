<?php

declare(strict_types=1);

namespace ON\DB\Middleware;

use ON\DB\DatabaseExtension;
use ON\DB\DatabasePoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DatabaseScopeMiddleware implements MiddlewareInterface
{
	private DatabasePoolInterface $pool;

	public function __construct(ContainerInterface $container)
	{
		$this->pool = DatabaseExtension::resolvePool($container);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (! $this->pool->openRequest()) {
			return $handler->handle($request);
		}

		try {
			return $handler->handle($request);
		} finally {
			$this->pool->closeRequest();
		}
	}
}
