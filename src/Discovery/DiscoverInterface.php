<?php

declare(strict_types=1);

namespace ON\Discovery;

interface DiscoverInterface
{
	public function getData(): mixed;

	public function setData(mixed $data): void;

	public function addData(mixed $data): void;

	public function apply(): bool;

	public function discover($file, DiscoveryLocation $location): void;
}
