<?php

declare(strict_types=1);

namespace ON\Discovery\CacheAdapter;

use ON\Application;
use ON\Config\AppConfig;
use ON\Discovery\DiscoverInterface;
use ON\Discovery\DiscoveryLocation;
use ON\FS\Path;

abstract class AbstractCacheAdapter implements CacheAdapterInterface {

    public function __construct (
		protected AppConfig $appCfg,
		protected Application $app
    ) {

    }
    abstract public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface;


    public function clear(?DiscoverInterface $discover = null, ?DiscoveryLocation $location = null): void
	{
		if ($discover === null || $location === null) {
			// Cannot determine cache file without both discover and location
			return;
		}

		$cacheFile = $this->cacheFilenameFromDiscover($discover, $location);

		if (file_exists($cacheFile)) {
			unlink($cacheFile);
		}
	}

	protected function cacheFilenameFromDiscover(DiscoverInterface $discover, DiscoveryLocation $location): string
	{
		$basePath = $this->appCfg->get("discovery.cache_path");
		if (! is_string($basePath) || trim($basePath) === '') {
			$basePath = $this->app->paths->get('cache')->append('discovery')->getAbsolutePath();
		} else {
			$basePath = Path::from($basePath, $this->app->paths->get('project'))
				->getAbsolutePath();
		}

		return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $this->classNameToFilename(get_class($discover), $location);
	}

	protected function classNameToFilename(string $className, DiscoveryLocation $location)
	{
		$filename = str_replace([' ', '\\'], '_', $className);
		$filename .= "." . $location->name;
		$filename .= '.cache.php';

		return $filename;
	}
    
}
