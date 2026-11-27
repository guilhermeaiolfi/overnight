<?php

declare(strict_types=1);

namespace ON\DB\Middleware;

use ON\DB\DatabaseContextInterface;
use ON\DB\DatabaseExtension;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DatabaseScopeMiddleware implements MiddlewareInterface
{
	private DatabaseContextInterface $context;

	public function __construct(ContainerInterface $container)
	{
		$this->context = DatabaseExtension::resolveContext($container);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$scope = $this->context->current();
		if ($scope === null || ! $scope->openRequest()) {
			return $handler->handle($request);
		}

		try {
			return $handler->handle($request);
		} finally {
			$scope->closeRequest();
		}
	}
}
