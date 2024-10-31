<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;

interface ExtensionInterface
{
	public static function install(Application $app, ?array $options = []): mixed;

	public function getType(): int;

	public function ready();

	public function setup(int $counter): bool;

	public function getPendingTasks(): array;

	public function removePendingTask(mixed $task): bool;

	public function hasPendingTask(mixed $task): bool;

	public function requires(): array;
}
