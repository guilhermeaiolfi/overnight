<?php

declare(strict_types=1);

namespace ON\Cache\Container;

use Exception;
use ON\Cache\Cache;
use ON\Cache\CacheConfig;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheFactory
{
	public function __invoke(ContainerInterface $c)
	{
		if (! $c->has(CacheConfig::class)) {
			throw new Exception("There is no CacheConfig object registered in the container. Please provide one.");
		}
		$config = $c->get(CacheConfig::class);

		$adapter = $config->get("adapter.class", FilesystemAdapter::class);

		$adapter = $c->get($adapter);

		$enable = $config->get("enable", false);

		return new Cache($adapter, $enable, (string) $config->get("adapter.namespace", ""));
	}
}
