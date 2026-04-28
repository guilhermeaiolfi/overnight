<?php

declare(strict_types=1);

namespace ON\Auth\Storage;

use ON\Session\SessionInterface;

class SessionStorage implements StorageInterface, AuthLifecycleStorageInterface
{
	public const NAMESPACE_DEFAULT = 'ONAuth';

	protected SessionInterface $session;

	public function __construct(
		SessionInterface $session,
		protected ?string $namespace = null
	) {
		if (! isset($namespace)) {
			$this->namespace = self::NAMESPACE_DEFAULT;
		}

		$this->session = $session;
	}

	public function isEmpty(): bool
	{
		return ! $this->session->has($this->namespace);
	}

	public function read(): mixed
	{
		return $this->session->get($this->namespace);
	}

	public function write(mixed $contents): void
	{
		$this->session->set($this->namespace, $contents);
	}

	public function clear(): void
	{
		$this->session->remove($this->namespace);
	}

	public function onLogin(): void
	{
		$this->session->regenerateId(true);
	}

	public function onLogout(): void
	{
		$this->session->regenerateId(true);
	}
}
