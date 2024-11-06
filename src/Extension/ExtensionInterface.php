<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;

interface ExtensionInterface
{
	public static function install(Application $app, ?array $options = []): mixed;

	public function getType(): int;

	public function setup(): void;

	public function boot(): void;

	public function requires(): array;

	public function getNamespace(): string;
}
