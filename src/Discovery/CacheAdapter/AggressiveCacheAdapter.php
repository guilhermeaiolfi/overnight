<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryItems;
use ON\Discovery\DiscoveryLocation;
use Symfony\Component\Finder\Finder;

class AggressiveCacheAdapter extends AbstractCacheAdapter
{
	public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface
	{
		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);

		$data = $this->readCacheFile($cacheFile);
		if ($data !== null) {
			$discover->addData(new DiscoveryItems($data->filterByLocation($location)));
		}

		return $discover;
	}

	public function hasCache(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);

		return ! isset($this->unreadableCacheFiles[$cacheFile]) && is_file($cacheFile);
	}

	public function update(array $discovers, DiscoveryLocation $location): void
	{

		$noCacheableDiscovers = array_filter($discovers, fn ($discover) => ! $this->hasCache($discover, $location));

		if (empty($noCacheableDiscovers)) {
			return;
		}

		$finder = new Finder();
		$finder->files()->in($location->pattern);
		foreach ($finder as $file) {
			foreach ($noCacheableDiscovers as $discover) {
				$discover->discover($file, $location);
			}
		}
	}

	public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		if (! $this->hasCache($discover, $location)) {
			$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);
			$this->writeCacheFile($cacheFile, new DiscoveryItems($discover->getData()->filterByLocation($location)));

			return true;
		}

		return false;
	}
}
