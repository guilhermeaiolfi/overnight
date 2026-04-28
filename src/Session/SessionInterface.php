<?php

declare(strict_types=1);

namespace ON\Session;

interface SessionInterface
{
	public function get(string $key, mixed $default = null): mixed;

	public function set(string $key, mixed $value = null): self;

	public function all(): array;

	public function has(string $key): bool;

	public function remove(string $key): void;

	public function destroy(): void;

	public function getId(): ?string;

	public function regenerateId(bool $deleteOldSession = false): bool;

	public function close(): bool;
}
