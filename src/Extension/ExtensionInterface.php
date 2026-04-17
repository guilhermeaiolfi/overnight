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

	public function dispatchStateChange(string $state, mixed $data = null): self;

	public function getHooks(): array;
}
