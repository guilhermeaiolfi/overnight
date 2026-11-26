<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryItems;
use ON\Discovery\DiscoveryLocation;
use Symfony\Component\Finder\Finder;

class TimestampCacheAdapter extends AbstractCacheAdapter
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
		return $this->getTimestamp($discover, $location) > 0;
	}

	public function update(array $discovers, DiscoveryLocation $location): void
	{
		$finder = new Finder();

		$timestamp = (int) $this->oldest($discovers, $location);
		$finder->files()->in($location->pattern)->date(">= " . date("d.m.Y H:i:s", $timestamp));

		// Pre-load all timestamps before the loop
		$timestamps = [];
		foreach ($discovers as $discover) {
			$timestamps[spl_object_id($discover)] = $this->getTimestamp($discover, $location);
		}

		foreach ($finder as $file) {
			foreach ($discovers as $discover) {
				$timestamp = $timestamps[spl_object_id($discover)];
				if ($file->getMTime() > $timestamp) {
					$discover->discover($file, $location);
				}
			}
		}
	}

	public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		if (! $this->hasCache($discover, $location) || $this->isDirty($discover, $location)) {
			$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);
			$this->writeCacheFile($cacheFile, new DiscoveryItems($discover->getData()->filterByLocation($location)));

			foreach ($discover->getData()->filterByLocation($location) as $item) {
				$item->setDirty(false);
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
			if ($item->isDirty()) {
				return true;
			}
		}

		return false;
	}

	protected function getTimestamp(DiscoverInterface $discover, DiscoveryLocation $location): float
	{
		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);

		return ! isset($this->unreadableCacheFiles[$cacheFile]) && is_file($cacheFile)
			? (@filemtime($cacheFile) ?: 0)
			: 0;
	}

	protected function oldest(array $discovers, DiscoveryLocation $location): float
	{
		$oldest = null;
		foreach ($discovers as $discover) {
			$timestamp = $this->getTimestamp($discover, $location);
			if ($timestamp == 0) {
				return 0;
			}
			if ($oldest === null || $timestamp < $oldest) {
				$oldest = $timestamp;
			}
		}

		return $oldest ?? 0;
	}
}
