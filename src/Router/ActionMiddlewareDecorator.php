<?php

declare(strict_types=1);

namespace ON\Router;

use Psr\Container\ContainerInterface;

/**
 * Wraps a "ClassName::method" route target into a structured object.
 *
 * Resolves the page instance from the container and exposes
 * the page, method, and class name for use by middleware in the pipeline
 * (ExecutionMiddleware, ValidationMiddleware, SecurityMiddleware, etc.).
 *
 * This class holds no execution logic — that lives in ExecutionMiddleware.
 */
class ActionMiddlewareDecorator
{
	protected mixed $instance = null;
	protected ?string $className = null;
	protected string $method = "index";

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
}
