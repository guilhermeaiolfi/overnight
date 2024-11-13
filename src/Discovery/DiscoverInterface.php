<?php

declare(strict_types=1);

namespace ON\Discovery;

interface DiscoverInterface
{
	public function cachedTimestamp(): float;

	public function save(): bool;

	public function recover(): bool;

	public function forget(): void;

	public function handle($file): bool;

	public function process(): bool;
}
