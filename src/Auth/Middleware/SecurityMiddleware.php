<?php

declare(strict_types=1);

namespace ON\Auth\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
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
			$loginPath = $this->config->get('controllers.login', false);

			if (! is_string($loginPath) || $loginPath === '') {
				return new EmptyResponse(401);
			}

			return $this->app->processForward($loginPath, $request);
		}

		return $handler->handle($request);
	}
}
