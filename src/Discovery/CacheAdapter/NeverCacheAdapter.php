<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryLocation;
use Symfony\Component\Finder\Finder;

class NeverCacheAdapter extends AbstractCacheAdapter {

    public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface
    {
        return $discover;
    }

	public function hasCache (DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		return false;
	}

	public function update(array $discovers, DiscoveryLocation $location): void
	{
		$finder = new Finder();

		$finder->files()->in($location->pattern);
		foreach ($finder as $file) {
			foreach ($discovers as $discover) {
				$discover->discover($file, $location);
			}
		}
	}

    public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		return false;
	}
}
