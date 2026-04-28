<?php

declare(strict_types=1);

namespace ON\Session\Native;

use function array_key_exists;
use function end;
use function explode;
use function is_dir;
use function is_file;
use ON\Session\SessionInterface;
use const PHP_SESSION_ACTIVE;
use function rtrim;
use RuntimeException;
use function session_destroy;
use function session_id;
use function session_module_name;
use function session_regenerate_id;
use function session_save_path;
use function session_start;
use function session_status;
use function session_write_close;
use function sprintf;
use function unlink;

class NativeSession implements SessionInterface
{
	/**
	 * Constructor for NativeSession class.
	 *
	 * @param array<string, mixed> $options Options for session start.
	 *                      Possible options:
	 *                      - 'save_path': Session save path
	 * @throws RuntimeException If session start fails.
	 **/
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

	private function getSessionFilePath(?string $id = null): ?string
	{
		$id ??= $this->getId();
		if ($id === null || session_module_name() !== 'files') {
			return null;
		}

		$savePath = $this->options['save_path'] ?? session_save_path();
		if ($savePath === '') {
			return null;
		}

		$parts = explode(';', $savePath);
		$directory = end($parts);
		if ($directory === false || $directory === '') {
			return null;
		}

		return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $id;
	}

	private function deleteSessionFileIfPossible(?string $sessionFile): void
	{
		if ($sessionFile === null || ! is_file($sessionFile)) {
			return;
		}

		@unlink($sessionFile);
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
		if (session_status() !== PHP_SESSION_ACTIVE) {
			$_SESSION = [];
			session_id('');

			return;
		}

		$sessionFile = $this->getSessionFilePath();
		$_SESSION = [];

		if ($sessionFile !== null) {
			session_write_close();
			$this->deleteSessionFileIfPossible($sessionFile);
		} else {
			session_destroy();
		}

		session_id('');
	}

	public function getId(): ?string
	{
		$id = session_id();

		return ($id !== false && $id !== '') ? $id : null;
	}

	public function regenerateId(bool $deleteOldSession = false): bool
	{
		$this->ensureStarted();

		if ($deleteOldSession) {
			$sessionFile = $this->getSessionFilePath();
			$result = session_regenerate_id(false);

			if (! $result) {
				return false;
			}

			$this->deleteSessionFileIfPossible($sessionFile);

			return true;
		}

		return session_regenerate_id($deleteOldSession);
	}

	public function close(): bool
	{
		return session_write_close();
	}
}
