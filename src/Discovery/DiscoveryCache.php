<?php

declare(strict_types=1);

namespace ON\Discovery;

class DiscoveryCache
{
	public const PATH = "var/cache/discovery/";

	public function save($discovery): bool
	{
		if ($discovery->isDirty()) {
			$cacheFile = self::PATH . $this->classNameToFilename(get_class($discovery));
			@mkdir(self::PATH, 0777, true);
			file_put_contents($cacheFile, serialize($discovery->getData()));

			return true;
		}

		return false;
	}

	public function clear(?DiscoverInterface $discover = null): void
	{
		if (! isset($discover)) {
			// TODO: remove all files in PATH
		}

		$cacheFile = $this->cacheFilenameFromDiscover($discover);
		if (file_exists($cacheFile)) {
			unlink($cacheFile);
		}
	}

	public function read(DiscoverInterface $discover): DiscoverInterface
	{
		$cacheFile = $this->cacheFilenameFromDiscover($discover);
		$data = file_get_contents($cacheFile);
		$data = unserialize($data);
		$discover->setData($data);

		return $discover;
	}

	public function timestamp(DiscoverInterface $discover): float
	{
		$cacheFile = $this->cacheFilenameFromDiscover($discover);

		return file_exists($cacheFile) ?
				filemtime($cacheFile) : 0;
	}

	protected function cacheFilenameFromDiscover(DiscoverInterface $discover): string
	{
		return self::PATH . $this->classNameToFilename(get_class($discover));
	}

	protected function classNameToFilename($className)
	{
		$filename = str_replace([' ', '\\'], '_', $className);
		$filename .= '.cache.php';

		return $filename;
	}
}
