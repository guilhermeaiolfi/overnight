<?php

declare(strict_types=1);

namespace ON\Auth\Middleware;

use ON\Application;
use ON\Auth\AuthenticationServiceInterface;
use ON\Config\AppConfig;
use ON\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecurityMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected AuthenticationServiceInterface $auth,
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

		if (method_exists($page, 'isSecure') && $page->isSecure() && ! $this->auth->hasIdentity()) {
			return $this->app->processForward($this->config->get('controllers.login'), $request);
		}

		return $handler->handle($request);
	}
}
