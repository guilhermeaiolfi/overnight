<?php

declare(strict_types=1);

namespace ON\Cache\Container;

use Exception;
use ON\Cache\CacheConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class FilesystemAdapterFactory
{
	public function __invoke(ContainerInterface $c)
	{
		if (! $c->has(CacheConfig::class)) {
			throw new Exception("There is no CacheConfig object registered in the container. Please provide one.");
		}
		$config = $c->get(CacheConfig::class);

		$directory = $config->get("adapter.directory", "var/cache/symfony");
		$namespace = $config->get("adapter.namespace", "ON");
		$defaultLifetime = $config->get("adapter.defaultLifetime", 0);

		$adapter = new FilesystemAdapter($namespace, $defaultLifetime, $directory);

		$logger_class = $config->get("adapter.logger", null);
		if ($logger_class != null) {
			$logger = $c->get(LoggerInterface::class);
			$adapter->setLogger($logger);
		}

		return $adapter;
	}
}
