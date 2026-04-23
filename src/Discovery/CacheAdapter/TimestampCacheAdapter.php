<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryLocation;
use Symfony\Component\Finder\Finder;

class TimestampCacheAdapter extends AbstractCacheAdapter {


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
        return $this->timestamp($discover, $location) > 0;
    }

	public function update(array $discovers, DiscoveryLocation $location): void
	{
		$finder = new Finder();

		$timestamp = (int) $this->oldest($discovers, $location);
		$finder->files()->in($location->pattern)->date(">= " . date("d.m.Y H:i:s", $timestamp));
		foreach ($finder as $file) {
			foreach ($discovers as $discover) {
				$timestamp = $this->timestamp($discover, $location);
				if ($file->getMTime() > $timestamp) {
					$discover->discover($file, $location);
				}
			}
		}
	}

    public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		if ($this->isDirty($discover, $location)) {
			$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);
			@mkdir(dirname($cacheFile), 0777, true);
			file_put_contents($cacheFile, serialize($discover->getData()));

			foreach ($discover->getData() as $item) {
				$item->setFresh(false);
			}
			return true;
		}

		return false;
	}

    protected function isDirty(DiscoverInterface $discover, DiscoveryLocation $location): bool
    {
        $items = $discover->getData();

        $items = $items->filterByLocation($location);

        foreach ($items as $item) {
            if ($item->isFresh()) {
                 return true;
            }
        }

        return false;
    }

    protected function timestamp(DiscoverInterface $discover, DiscoveryLocation $location): float
	{
		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);

		return file_exists($cacheFile) ?
				filemtime($cacheFile) : 0;
	}

    protected function oldest(array $discovers, DiscoveryLocation $location): float
    {
        $oldest = 0;
		foreach ($discovers as $discover) {
			$timestamp = $this->timestamp($discover, $location);
			if ($oldest == 0 || $oldest > $timestamp) {
				$oldest = $timestamp;
			}
        }
        return $oldest;
    }
}
