<?php

declare(strict_types=1);

namespace ON\RateLimit;

use ON\Application;
use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\RateLimit\Container\RateLimiterFactory;

class RateLimitExtension extends AbstractExtension
{
	public const ID = 'ratelimit';

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		$init->on(ContainerConfigureEvent::class, function (ContainerConfigureEvent $event): void {
			$event->containerConfig->addFactories([
				RateLimiterInterface::class => RateLimiterFactory::class,
			]);
		});
	}
}
