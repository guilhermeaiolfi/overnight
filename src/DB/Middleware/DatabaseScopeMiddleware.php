<?php

declare(strict_types=1);

namespace ON\DB\Middleware;

use ON\DB\DatabaseManager;
use ON\ExecutionContextInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DatabaseScopeMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ContainerInterface $container,
		private ExecutionContextInterface $executionContext,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$contextId = $this->executionContext->getId();
		if ($contextId === null) {
			return $handler->handle($request);
		}

		$manager = $this->container->get(DatabaseManager::class);
		$ownsScope = $manager->openRequestScope($contextId);

		try {
			return $handler->handle($request);
		} finally {
			if ($ownsScope) {
				$manager->closeRequestScope($contextId);
			}
		}
	}
}
