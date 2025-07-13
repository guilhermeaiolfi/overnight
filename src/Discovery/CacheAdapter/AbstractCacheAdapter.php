<?php

namespace ON\Discovery\CacheAdapter;

use ON\Config\AppConfig;
use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryCache;
use ON\Discovery\DiscoveryLocation;

abstract class AbstractCacheAdapter implements CacheAdapterInterface {

    public function __construct (
        protected DiscoveryCache $cache,
		protected AppConfig $appCfg
    ) {

    }
    abstract public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface;


    public function clear(?DiscoverInterface $discover = null, ?DiscoveryLocation $location = null): void
	{
		$cacheFiles = [];
		if (! isset($discover)) {
			throw new \Exception("TODO: remove all files from a determined location or not (if not available)");
		}

		if (!isset($location)) {
			// TODO: get files from all locations
			throw new \Exception("TODO: implement removing all files for all location to a defined discover.");
		}

		if (isset($discover) && isset($location)) {
			$cacheFiles[] = $this->cacheFilenameFromDiscover($discover, $location);
		}

		foreach ($cacheFiles as $cacheFile)
		{
			if (file_exists($cacheFile)) {
				unlink($cacheFile);
			}
		}
	}

    protected function cacheFilenameFromDiscover(DiscoverInterface $discover, DiscoveryLocation $location): string
	{
		return $this->appCfg->get("discovery.cache_path", "var/cache/discovery/") . $this->classNameToFilename(get_class($discover), $location);
	}

	protected function classNameToFilename(string $className, DiscoveryLocation $location)
	{
		$filename = str_replace([' ', '\\'], '_', $className);
		$filename .= "." . $location->name;
		$filename .= '.cache.php';

		return $filename;
	}
    
}