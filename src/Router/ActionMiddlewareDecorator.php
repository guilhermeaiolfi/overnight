<?php

declare(strict_types=1);

namespace ON\Router;

use Exception;
use Laminas\Diactoros\Response\EmptyResponse;
use ON\Application;
use ON\Router\RouteResult;
use ReflectionMethod;
use ReflectionNamedType;
use ON\Auth\AuthenticationServiceInterface;
use ON\Config\AppConfig;
use ON\Container\Executor\ExecutorInterface;
use ON\View\ViewBuilderTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ActionMiddlewareDecorator implements MiddlewareInterface
{
	use ViewBuilderTrait;
	protected mixed $instance = null;

	protected ExecutorInterface $executor;

	protected ?string $className = null;
	protected string $method = "index";

	protected Application $app;

	public function __construct(
		protected ContainerInterface $container,
		public readonly string $middlewareName,
	) {
		if (is_string($middlewareName) && strpos($middlewareName, "::") !== false) {

			[$className, $method] = explode("::", $middlewareName);

			$this->className = $className;
			$this->method = $method;

			$this->instance = $this->container->get($className);
		}

		$this->app = $this->container->get(Application::class);

		$this->executor = $this->container->get(ExecutorInterface::class);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $this->execute($request, $handler);
	}

	public function execute(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$args = $this->resolveRouteParams($request);
		$args[ServerRequestInterface::class] = $request;
		$args[RequestHandlerInterface::class] = $handler;

		$action_response = $this->executor->execute([$this->instance, $this->method], $args);

		return $this->buildView($this->instance, $this->method, $action_response, $request, $handler);
	}

	protected function resolveRouteParams(ServerRequestInterface $request): array
	{
		$args = [];
		$result = $request->getAttribute(RouteResult::class);

		if (! $result || ! $result->isSuccess()) {
			return $args;
		}

		$routeParams = $result->getMatchedParams();
		$method = new ReflectionMethod($this->instance, $this->method);

		foreach ($method->getParameters() as $param) {
			$paramName = $param->getName();

			if (isset($routeParams[$paramName])) {
				$type = $param->getType();

				if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
					$value = $routeParams[$paramName];
					settype($value, $type->getName());
					$args[$paramName] = $value;
				} elseif (! $type) {
					$args[$paramName] = $routeParams[$paramName];
				}
			}
		}

		return $args;
	}

	public function getClassName(): string
	{
		return $this->className;
	}

	public function getPageInstance(): mixed
	{
		return $this->instance;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function loggedCheck(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{

		$page = $this->instance;

		$auth = $this->container->get(AuthenticationServiceInterface::class);

		if (! $auth) {
			throw new Exception("SecurityMiddleware needs an AuthenticationServiceInterface registered in the container");
		}

		if (method_exists($page, "isSecure") && $page->isSecure() && ! $auth->hasIdentity()) {
			$config = $this->container->get(AppConfig::class);

			//throw new Exception('User has no permittion!');
			return $this->app->processForward($config->get('controllers.login'), $request);
			//return new RedirectResponse($this->router->gen("login"));
		}

		return $handler->handle($request);
	}

	public function checkPermissions(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$page = $this->instance;

		$checkPermissionsMethod = 'check' . ucfirst($this->method) . 'Permissions';
		if (! method_exists($page, $checkPermissionsMethod)) {
			$checkPermissionsMethod = 'checkPermissions';
		}

		if (! method_exists($page, $checkPermissionsMethod)) {
			$checkPermissionsMethod = 'defaultCheckPermissions';
		}
		// TODO: do we need to wrap this in a try/catch block? what happens if an exception is thrown in checkPermissions()?
		if (method_exists($page, $checkPermissionsMethod)) {
			$args = [
				ServerRequestInterface::class => $request,
			];
			$ok = $this->executor->execute([$page, $checkPermissionsMethod], $args);
			if ($ok) {
				return $handler->handle($request);
			} else {
				$config = $this->container->get(AppConfig::class);
				$middleware = $config->get('controllers.errors.403', false);
				if (! $middleware) {
					return new EmptyResponse(403);
				}

				return $this->app->processForward($middleware, $request);
			}
		}

		return $handler->handle($request);
	}
}
