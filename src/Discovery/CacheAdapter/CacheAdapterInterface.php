<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryLocation;

interface CacheAdapterInterface {

    public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface;

    public function hasCache(DiscoverInterface $discover, DiscoveryLocation $location): bool;

	public function update(array $discovers, DiscoveryLocation $location): void;

    public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool;
}