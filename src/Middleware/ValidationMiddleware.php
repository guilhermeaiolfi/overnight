<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Container\Executor\ExecutorInterface;
use ON\Http\InvocationContext;
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

		if (! $routeResult) {
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
		$args = InvocationContext::fromRequest($request)->applyToArgs($args);

		$valid = $this->executor->execute([$page, $validateMethod], $args);

		if ($valid instanceof ValidationResult) {
			$request = $valid->request();
			$args[ServerRequestInterface::class] = $request;
			$args = InvocationContext::fromRequest($request)->applyToArgs($args);
			$valid = $valid->isValid();
		}

		if ($valid === true) {
			return $handler->handle($request);
		}

		// Validation failed — call error handler on the page
		$errorMethod = $this->findErrorMethod($page, $action);

		if ($errorMethod === null) {
			return $handler->handle($request);
		}

		$response = $this->executor->execute([$page, $errorMethod], $args);

		// We don't need to test $response as ResponseInterface, because that's done in the runView already
		// that means that handleError can return an ResponseInterface directly, or return data to be rendered in the view
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

	protected function findErrorMethod(object $page, ?string $action): ?string
	{
		$method = 'handle' . ucfirst($action) . 'Error';
		if (method_exists($page, $method)) {
			return $method;
		}

		if (method_exists($page, 'handleError')) {
			return 'handleError';
		}

		if (method_exists($page, 'defaultHandleError')) {
			return 'defaultHandleError';
		}

		return null;
	}
}
