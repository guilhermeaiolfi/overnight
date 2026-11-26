<?php

declare(strict_types=1);

namespace ON\Session\Native;

use function array_key_exists;
use function end;
use function explode;
use function headers_sent;
use function ini_get;
use function is_dir;
use function is_string;
use ON\Session\SessionInterface;
use const PHP_SESSION_ACTIVE;
use RuntimeException;
use function session_destroy;
use function session_get_cookie_params;
use function session_id;
use function session_module_name;
use function session_name;
use function session_regenerate_id;
use function session_start;
use function session_status;
use function session_write_close;
use function setcookie;
use function sprintf;
use function time;

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

		$savePath = $this->options['save_path'] ?? null;
		$saveHandler = $this->options['save_handler'] ?? session_module_name();
		if (is_string($savePath) && $savePath !== '' && $saveHandler === 'files') {
			$parts = explode(';', $savePath);
			$directory = end($parts);
			if ($directory === false || $directory === '' || ! is_dir($directory)) {
				throw new RuntimeException(
					sprintf('Session save path "%s" does not exist.', $savePath)
				);
			}
		}

		if (! session_start($this->options)) {
			throw new RuntimeException('Failed to start the session.');
		}
	}

	private function expireSessionCookie(): void
	{
		if (! (bool) ini_get('session.use_cookies') || headers_sent()) {
			return;
		}

		$name = session_name();
		if ($name === false || $name === '') {
			return;
		}

		$params = session_get_cookie_params();
		$cookieOptions = [
			'expires' => time() - 42000,
			'path' => $params['path'] ?? '/',
			'domain' => $params['domain'] ?? '',
			'secure' => (bool) ($params['secure'] ?? false),
			'httponly' => (bool) ($params['httponly'] ?? false),
		];

		if (isset($params['samesite']) && $params['samesite'] !== '') {
			$cookieOptions['samesite'] = $params['samesite'];
		}

		setcookie($name, '', $cookieOptions);
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

		$this->expireSessionCookie();
		$_SESSION = [];
		session_destroy();
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

		return session_regenerate_id($deleteOldSession);
	}

	public function close(): bool
	{
		return session_write_close();
	}
}
