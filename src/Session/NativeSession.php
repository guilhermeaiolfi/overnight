<?php

declare(strict_types=1);

namespace ON\Session;

use function array_key_exists;
use function is_dir;
use const PHP_SESSION_ACTIVE;
use RuntimeException;
use function session_destroy;
use function session_id;
use function session_regenerate_id;
use function session_start;
use function session_status;
use function session_write_close;
use function sprintf;

class NativeSession implements SessionInterface
{
	/**
	 * @param array<string, mixed> $options Options forwarded to session_start()
	 */
	public function __construct(
		private array $options = []
	) {
	}

	/**
	 * Start the session if it is not already active.
	 *
	 * @throws RuntimeException If the session cannot be started.
	 */
	private function ensureStarted(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			return;
		}

		if (isset($this->options['save_path']) && ! is_dir($this->options['save_path'])) {
			throw new RuntimeException(
				sprintf('Session save path "%s" does not exist.', $this->options['save_path'])
			);
		}

		if (! session_start($this->options)) {
			throw new RuntimeException('Failed to start the session.');
		}
	}

	public function get(string $key, mixed $default = null): mixed
	{
		$this->ensureStarted();

		return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
	}

	public function set(string $key, mixed $value = null): self
	{
		$this->ensureStarted();
		$_SESSION[$key] = $value;

		return $this;
	}

	public function all(): array
	{
		$this->ensureStarted();

		return $_SESSION;
	}

	public function has(string $key): bool
	{
		$this->ensureStarted();

		return array_key_exists($key, $_SESSION);
	}

	public function remove(string $key): void
	{
		$this->ensureStarted();
		unset($_SESSION[$key]);
	}

	public function destroy(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}

		$_SESSION = [];
	}

	public function getId(): ?string
	{
		$id = session_id();

		return ($id !== false && $id !== '') ? $id : null;
	}

	public function regenerateId(bool $deleteOldSession = false): bool
	{
		$this->ensureStarted();

		return session_regenerate_id($deleteOldSession);
	}

	public function close(): bool
	{
		return session_write_close();
	}
}
