<?php

declare(strict_types=1);

namespace ON\View;

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
 *       return new HtmlResponse($this->view->render('default', 'post/success', $result->data));
 *   }
 *
 * Or destructure what you need (via Executor parameter injection):
 *   public function successView(array $post, string $message) { ... }
 */
class ViewResult implements \ArrayAccess
{
	public function __construct(
		public readonly string $view,
		public readonly array $data = []
	) {
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
