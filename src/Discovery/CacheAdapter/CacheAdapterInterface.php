<?php

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryCache;
use ON\Discovery\DiscoveryLocation;
use Symfony\Component\Finder\Finder;

interface CacheAdapterInterface {

    public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface;

    public function hasCache(DiscoverInterface $discover, DiscoveryLocation $location): bool;

	public function update(array $discovers, DiscoveryLocation $location): void;

    public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool;
}