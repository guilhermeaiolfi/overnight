<?php

declare(strict_types=1);

namespace ON\Discovery;

use SplFileInfo;

interface DiscoverInterface
{
	public function getData(): DiscoveryItems;

	public function setData(DiscoveryItems $data): void;

	public function addData(DiscoveryItems $data): void;

	public function apply(): bool;

	public function discover(SplFileInfo $file, DiscoveryLocation $location): void;
}
