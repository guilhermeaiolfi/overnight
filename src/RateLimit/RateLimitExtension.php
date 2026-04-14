<?php

declare(strict_types=1);

namespace ON\RateLimit;

use ON\Application;
use ON\Cache\CacheInterface;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;

class RateLimitExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);
		$app->registerExtension('ratelimit', $extension);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function requires(): array
	{
		return ['container'];
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('setup', function () {
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

		$this->setState('ready');
	}

	public function setup(): void
	{
	}
}
