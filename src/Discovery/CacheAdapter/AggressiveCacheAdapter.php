<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryLocation;
use Symfony\Component\Finder\Finder;

class AggressiveCacheAdapter extends AbstractCacheAdapter {

    public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface
    {
        $cacheFile = $this->cacheFilenameFromDiscover($discover, $location);
        
        if (!file_exists($cacheFile)) {
            return $discover;
        }

		$data = file_get_contents($cacheFile);
		$data = unserialize($data);
		$discover->addData($data);
        return $discover;
    }

    public function hasCache(DiscoverInterface $discover, DiscoveryLocation $location): bool {
		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);
		return file_exists($cacheFile);
    }

	public function update(array $discovers, DiscoveryLocation $location): void
	{
		$finder = new Finder();

		$finder->files()->in($location->pattern);
		foreach ($finder as $file) {
			foreach ($discovers as $discover) {
				if ($this->hasCache($discover, $location)) {
					continue;
				}
				$discover->discover($file, $location);
			}
		}
	}

    public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		if (!$this->hasCache($discover, $location)) {
			$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);
			@mkdir(dirname($cacheFile), 0777, true);
			file_put_contents($cacheFile, serialize($discover->getData()));

			return true;
		}

		return false;
	}
}
