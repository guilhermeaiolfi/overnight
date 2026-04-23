<?php

declare(strict_types=1);

namespace ON\Router;

use function assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Value object representing the results of routing.
 *
 * Contains:
 * - Whether routing succeeded or failed
 * - The matched route, parameters, and route name
 * - For "ClassName::method" routes: the resolved target instance and method
 *
 * The target instance and method are set by PipelineExtension when it
 * resolves a "ClassName::method" string from the route definition.
 * Downstream middleware (Execution, Validation, Security, Authorization)
 * read these directly — no intermediate decorator needed.
 */
class RouteResult implements MiddlewareInterface
{
	/** @var list<string>|null */
	private $allowedMethods = [];

	/** @var array<string, mixed> */
	private $matchedParams = [];

	/** @var string|null */
	private $matchedRouteName;

	/**
	 * Route matched during routing
	 *
	 * @since 1.3.0
	 * @var Route|null
	 */
	private $route;

	protected array $values = [];

	/**
	 * The resolved target instance (page, controller, etc.) for "ClassName::method" routes.
	 * Null for regular PSR-15 middleware routes.
	 */
	private ?object $targetInstance = null;

	/**
	 * The method to call on the target instance.
	 */
	private string $method = 'index';

	/**
	 * Only allow instantiation via factory methods.
	 *
	 * @param bool $success Success state of routing.
	 */
	private function __construct(private bool $success)
	{
	}

	public function set(string $key, mixed $value): void
	{
		$this->values[$key] = $value;
	}

	public function get(string $key): mixed
	{
		return $this->values[$key] ?? null;
	}

	/**
	 * Create an instance representing a route success from the matching route.
	 *
	 * @param array<string, mixed> $params Parameters associated with the matched route, if any.
	 */
	public static function fromRoute(Route $route, array $params = []): self
	{
		$result = new self(true);
		$result->route = $route;
		$result->matchedParams = $params;

		return $result;
	}

	/**
	 * Create an instance representing a route failure.
	 *
	 * @param null|list<string> $methods HTTP methods allowed for the current URI, if any.
	 *     null is equivalent to allowing any HTTP method; empty array means none.
	 */
	public static function fromRouteFailure(?array $methods): self
	{
		$result = new self(false);
		$result->allowedMethods = $methods;

		return $result;
	}

	/**
	 * Process the result as middleware.
	 *
	 * If the result represents a failure, it passes handling to the handler.
	 *
	 * Otherwise, it processes the composed middleware using the provided request
	 * and handler.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->isFailure()) {
			return $handler->handle($request);
		}

		$route = $this->getMatchedRoute();
		assert($route instanceof MiddlewareInterface);

		return $route->process($request, $handler);
	}

	/**
	 * Does the result represent successful routing?
	 */
	public function isSuccess(): bool
	{
		return $this->success;
	}

	/**
	 * Retrieve the route that resulted in the route match.
	 *
	 * @return false|Route false if representing a routing failure; Route instance otherwise.
	 */
	public function getMatchedRoute()
	{
		return $this->route ?? false;
	}

	/**
	 * Retrieve the matched route name, if possible.
	 *
	 * If this result represents a failure, return false; otherwise, return the
	 * matched route name.
	 *
	 * @return false|string
	 */
	public function getMatchedRouteName()
	{
		if ($this->isFailure()) {
			return false;
		}

		if (! $this->matchedRouteName) {
			assert($this->route !== null);
			$this->matchedRouteName = $this->route->getName();
		}

		return $this->matchedRouteName;
	}

	/**
	 * Returns the matched params.
	 *
	 * Guaranteed to return an array, even if it is simply empty.
	 *
	 * @return array<string, mixed>
	 */
	public function getMatchedParams(): array
	{
		return $this->matchedParams;
	}

	/**
	 * Is this a routing failure result?
	 */
	public function isFailure(): bool
	{
		return ! $this->success;
	}

	/**
	 * Does the result represent failure to route due to HTTP method?
	 */
	public function isMethodFailure(): bool
	{
		if ($this->isSuccess() || $this->allowedMethods === Route::HTTP_METHOD_ANY) {
			return false;
		}

		return true;
	}

	/**
	 * Retrieve the allowed methods for the route failure.
	 *
	 * @return null|list<string> HTTP methods allowed
	 */
	public function getAllowedMethods(): ?array
	{
		if ($this->isSuccess()) {
			assert($this->route !== null);

			return $this->route->getAllowedMethods();
		}

		return $this->allowedMethods;
	}

	// --- Target instance (page/controller) ---

	public function setTargetInstance(object $instance): void
	{
		$this->targetInstance = $instance;
	}

	public function getTargetInstance(): ?object
	{
		return $this->targetInstance;
	}

	public function setMethod(string $method): void
	{
		$this->method = $method;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	/**
	 * Whether this route result has a resolved target instance (page/controller).
	 */
	public function hasTargetInstance(): bool
	{
		return $this->targetInstance !== null;
	}
}
