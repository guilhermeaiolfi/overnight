<?php

declare(strict_types=1);

namespace ON\Discovery;

interface DiscoverInterface
{
	public function getData(): mixed;

	public function setData(mixed $data): void;

	public function apply(): bool;

	public function discover(string $file): void;

	public function isDirty(): bool;
}
