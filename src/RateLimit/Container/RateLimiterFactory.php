<?php

declare(strict_types=1);

namespace ON\RateLimit\Container;

use ON\Cache\CacheInterface;
use ON\RateLimit\CacheRateLimiter;
use ON\RateLimit\InMemoryRateLimiter;
use ON\RateLimit\RateLimiterInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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

		if ($container->has(LoggerInterface::class)) {
			$logger = $container->get(LoggerInterface::class);
			if ($logger instanceof LoggerInterface) {
				$logger->warning('Cache is unavailable or disabled; falling back to InMemoryRateLimiter.');
			}
		}

		return new InMemoryRateLimiter();
	}
}
