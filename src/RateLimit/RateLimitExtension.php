<?php

declare(strict_types=1);

namespace ON\RateLimit;

use ON\Application;
use ON\Cache\CacheInterface;
use ON\Container\ContainerConfig;
use ON\Container\Init\ContainerInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;

class RateLimitExtension extends AbstractExtension
{
	public const ID = 'ratelimit';
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return ['container'];
	}

	public function register(Init $init): void
	{
		$init->on(ContainerInitEvents::CONFIGURE, function (): void {
			$containerConfig = $this->app->config->get(ContainerConfig::class);

			$containerConfig->addFactories([
				RateLimiterInterface::class => function ($container) {
					// Use cache-based limiter in production, in-memory for dev
					if ($container->has(CacheInterface::class)) {
						$cache = $container->get(CacheInterface::class);
						if ($cache->isEnabled()) {
							return new CacheRateLimiter($cache);
						}
					}

					return new InMemoryRateLimiter();
				},
			]);

		});
	}

}
