<?php

declare(strict_types=1);

namespace ON\Session;

use const PHP_SESSION_NONE;
use RuntimeException;
use function session_start;
use function session_status;

class NativeSession implements SessionInterface
{
	private array $data;

	/**
	 * Constructor for NativeSession class.
	 *
	 * @param array $options Options for session start.
	 *                      Possible options:
	 *                      - 'name': Session name
	 *                      - 'lifetime': Session lifetime
	 *                      - 'path': Session save path
	 *                      - 'domain': Session domain
	 *                      - 'secure': Set to true for secure session
	 *                      - 'httponly': Set to true to only allow HTTP access
	 * @throws RuntimeException If session start fails.
	 */
	public function __construct(array $options = [])
	{
		if (session_status() === PHP_SESSION_NONE) {
			if (isset($options['save_path']) && ! is_dir($options['save_path'])) {
				throw new RuntimeException(sprintf('Session save path "%s" does not exist.', $options['save_path']));
			}
			if (! session_start($options)) {
				throw new RuntimeException('Failed to start the session.');
			}
		}

		$this->data = &$_SESSION;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		if ($this->has($key)) {
			return $this->data[$key];
		}

		return $default;
	}

	public function set(string $key, mixed $value = null): self
	{
		$this->data[$key] = $value;

		return $this;
	}

	public function all(): array
	{
		return $this->data;
	}

	public function has(string $key): bool
	{
		return isset($this->data[$key]);
	}

	public function remove(string $key): void
	{
		if ($this->has($key)) {
			unset($this->data[$key]);
		}
	}

	public function destroy(): void
	{
		session_destroy();
	}

	public function getId(): ?string
	{
		$id = session_id();
		if ($id !== false) {
			return $id;
		}

		return null;
	}
}
