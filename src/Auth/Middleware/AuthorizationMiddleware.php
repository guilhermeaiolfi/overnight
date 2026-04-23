<?php

declare(strict_types=1);

namespace ON\Auth\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use ON\Application;
use ON\Config\AppConfig;
use ON\Container\Executor\ExecutorInterface;
use ON\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthorizationMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected ExecutorInterface $executor,
		protected Application $app,
		protected AppConfig $config
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);

		if (! $routeResult) {
			return $handler->handle($request);
		}

		$page = $routeResult->getTargetInstance();
		$action = $routeResult->getMethod();

		$checkMethod = $this->findCheckPermissionsMethod($page, $action);

		if ($checkMethod === null) {
			return $handler->handle($request);
		}

		$args = [
			ServerRequestInterface::class => $request,
		];

		$ok = $this->executor->execute([$page, $checkMethod], $args);

		if ($ok) {
			return $handler->handle($request);
		}

		$errorMiddleware = $this->config->get('controllers.errors.403', false);

		if (! $errorMiddleware) {
			return new EmptyResponse(403);
		}

		return $this->app->processForward($errorMiddleware, $request);
	}

	protected function findCheckPermissionsMethod(object $page, string $action): ?string
	{
		$method = 'check' . ucfirst($action) . 'Permissions';
		if (method_exists($page, $method)) {
			return $method;
		}

		if (method_exists($page, 'checkPermissions')) {
			return 'checkPermissions';
		}

		if (method_exists($page, 'defaultCheckPermissions')) {
			return 'defaultCheckPermissions';
		}

		return null;
	}
}
