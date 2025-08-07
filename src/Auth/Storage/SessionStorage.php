<?php

declare(strict_types=1);

namespace ON\Auth\Storage;

use ON\Session\SessionInterface;
use ON\Session\SessionManagerInterface;

class SessionStorage implements StorageInterface
{
	public const NAMESPACE_DEFAULT = 'ONAuth';

	protected SessionInterface $session;

	public function __construct(
		protected SessionManagerInterface $manager,
		protected ?string $namespace = null
	) {
		if (! isset($namespace)) {
			$this->namespace = self::NAMESPACE_DEFAULT;
		}

		$this->session = $manager->resolve();
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
}
