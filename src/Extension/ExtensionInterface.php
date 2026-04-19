<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;

interface ExtensionInterface
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface;

	public function getType(): int;

	public function setup(): void;

	public function boot(): void;

	public function requires(): array;

	public function getNamespace(): string;

	public function isReady(): bool;

	public function getState(): string;

	public function getStateHistory(): array;

	public function when(string $state, callable $callback): self;

	public function dispatchStateChange(string $state, mixed $data = null): self;

	public function setLifecycle(ExtensionLifecycle $lifecycle): void;

	public function transitionTo(string $state): void;

	public function isInState(string $state): bool;

	public function wasInState(string $state): bool;

	public function getHooks(): array;
}
