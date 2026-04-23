<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Container\Executor\ExecutorInterface;
use ON\Router\RouteResult;
use ON\View\ViewManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ValidationMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected ExecutorInterface $executor,
		protected ViewManager $viewManager
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeResult = $request->getAttribute(RouteResult::class, false);

		if (!$routeResult) {
			return $handler->handle($request);
		}

		$page = $routeResult->getTargetInstance();
		$action = $routeResult->getMethod();

		if ($page === null) {
			return $handler->handle($request);
		}

		$validateMethod = $this->findValidateMethod($page, $action);

		if ($validateMethod === null) {
			return $handler->handle($request);
		}

		$args = [
			ServerRequestInterface::class => $request,
		];

		$valid = $this->executor->execute([$page, $validateMethod], $args);

		if ($valid) {
			return $handler->handle($request);
		}

		// Validation failed — call error handler on the page
		$errorMethod = $this->findErrorMethod($page);

		if ($errorMethod === null) {
			return $handler->handle($request);
		}

		$response = $this->executor->execute([$page, $errorMethod], $args);

		return $this->viewManager->runView($page, $action, $response, $request, $handler, $this->executor);
	}

	protected function findValidateMethod(object $page, string $action): ?string
	{
		$method = $action . 'Validate';
		if (method_exists($page, $method)) {
			return $method;
		}

		if (method_exists($page, 'validate')) {
			return 'validate';
		}

		if (method_exists($page, 'defaultValidate')) {
			return 'defaultValidate';
		}

		return null;
	}

	protected function findErrorMethod(object $page): ?string
	{
		if (method_exists($page, 'handleError')) {
			return 'handleError';
		}

		if (method_exists($page, 'defaultHandleError')) {
			return 'defaultHandleError';
		}

		return null;
	}
}
