<?php

declare(strict_types=1);

namespace ON\Session;

interface SessionInterface
{
	public function get(string $key,  $default = null);

	public function set(string $key, $value = null): self;

	public function all(): array;

	public function has(string $key): bool;

	public function remove(string $key): void;

	public function destroy(): void;

	public function getId(): ?string;
}
