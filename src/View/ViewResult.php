<?php

declare(strict_types=1);

namespace ON\View;

use Psr\Http\Message\RequestInterface;

/**
 * Value object returned by action methods to indicate which view method
 * to call and what data to pass to it.
 *
 * Provides convenient access to data via get(), has(), and ArrayAccess.
 *
 * Usage in action:
 *   return new ViewResult('success', ['post' => $post, 'message' => 'Created']);
 *
 * Usage in view method (type-hint ViewResult to get the full object):
 *   public function successView(ViewResult $result) {
 *       $post = $result->get('post');
 *       return new HtmlResponse($this->viewManager->render($result->toArray(), 'post/success', 'default'));
 *   }
 *
 * Or destructure what you need (via Executor parameter injection):
 *   public function successView(array $post, string $message) { ... }
 */
class ViewResult implements \ArrayAccess
{
	protected ?string $pageClass = null;
	protected ?string $viewName = null; // eg.: 'success', 'error'
	protected ?string $actionName = null;
	protected ?object $targetObject = null;

	public function __construct(
		protected readonly string $view,
		protected readonly array $data = []
	) {
		if (strpos($view, ":") !== false) {
			$parts = explode(":", $view);
			$this->pageClass = $parts[0] . 'Page';
			$this->viewName = strtolower($parts[1]);
		} else {
			$this->viewName = strtolower($this->view);
		}
	}

	public function getTargetObject(): ?object
	{
		return $this->targetObject;
	}

	public function setTargetObject(object $targetObject): void
	{
		$this->targetObject = $targetObject;
	}

	public function getTemplateName(): string
	{
		$targetObject = $this->getTargetObject();
		if (!isset($targetObject)) {
			throw new \LogicException('Target object must be set to get template name.');
		}
		$path = explode("\\", get_class($targetObject));
		return strtolower($path[0] . "::" . str_replace("Page", "", array_pop($path)) . "-" . $this->actionName . "-" . strtolower($this->viewName));
	}

	public function getViewMethod(): string
	{
		$method = strtolower($this->viewName) . 'View';
		if (method_exists($this->targetObject, $method)) {
			return $method;
		}

		$method = $this->actionName . ucfirst($this->viewName) . 'View';
		if (method_exists($this->targetObject, $method)) {
			return $method;
		}

		$method = strtolower($this->viewName);
		if (method_exists($this->targetObject, $method)) {
			return $method;
		}

		$className = get_class($this->targetObject);
		throw new \Exception("No view method found for '{$this->viewName}' in class {$className}");
	}

	public function setActionName(string $actionName): void
	{
		$this->actionName = $actionName;
	}

	public function getActionName(): ?string
	{
		return $this->actionName;
	}

	public function getPageClass(): ?string
	{
		return $this->targetObject ? get_class($this->targetObject) : $this->pageClass;
	}

	public function getViewName(): ?string
	{
		return $this->viewName;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->data[$key] ?? $default;
	}

	public function has(string $key): bool
	{
		return array_key_exists($key, $this->data);
	}

	public function toArray(): array
	{
		return $this->data;
	}

	// ArrayAccess — read-only

	public function offsetExists(mixed $offset): bool
	{
		return $this->has($offset);
	}

	public function offsetGet(mixed $offset): mixed
	{
		return $this->get($offset);
	}

	public function offsetSet(mixed $offset, mixed $value): void
	{
		throw new \LogicException('ViewResult is immutable.');
	}

	public function offsetUnset(mixed $offset): void
	{
		throw new \LogicException('ViewResult is immutable.');
	}
}
