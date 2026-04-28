<?php

declare(strict_types=1);

namespace ON\Session\Native;

class NamespacedSession implements SessionInterface
{
	public function __construct(
		private SessionInterface $session,
		private string $namespace
	) {
	}

	public function get(string $key, mixed $default = null): mixed
	{
		$data = $this->session->get($this->namespace, []);

		return array_key_exists($key, $data) ? $data[$key] : $default;
	}

	public function set(string $key, mixed $value = null): self
	{
		$data = $this->session->get($this->namespace, []);
		$data[$key] = $value;
		$this->session->set($this->namespace, $data);

		return $this;
	}

	public function all(): array
	{
		return $this->session->get($this->namespace, []);
	}

	public function has(string $key): bool
	{
		$data = $this->session->get($this->namespace, []);

		return array_key_exists($key, $data);
	}

	public function remove(string $key): void
	{
		$data = $this->session->get($this->namespace, []);
		unset($data[$key]);
		$this->session->set($this->namespace, $data);
	}

	public function destroy(): void
	{
		$this->session->remove($this->namespace);
	}

	public function getId(): ?string
	{
		return $this->session->getId();
	}

	public function regenerateId(bool $deleteOldSession = false): bool
	{
		return $this->session->regenerateId($deleteOldSession);
	}

	public function close(): bool
	{
		return $this->session->close();
	}
}
