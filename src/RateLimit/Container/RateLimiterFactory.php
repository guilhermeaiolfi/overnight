<?php

declare(strict_types=1);

namespace ON\RateLimit\Container;

use ON\Cache\CacheInterface;
use ON\RateLimit\CacheRateLimiter;
use ON\RateLimit\InMemoryRateLimiter;
use ON\RateLimit\RateLimiterInterface;
use Psr\Container\ContainerInterface;

class RateLimiterFactory
{
	public function __invoke(ContainerInterface $container): RateLimiterInterface
	{
		// Use cache-based limiter if cache is available and enabled
		if ($container->has(CacheInterface::class)) {
			$cache = $container->get(CacheInterface::class);
			if ($cache->isEnabled()) {
				return new CacheRateLimiter($cache);
			}
		}

		return new InMemoryRateLimiter();
	}
}
