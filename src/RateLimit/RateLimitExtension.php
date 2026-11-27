<?php

declare(strict_types=1);

namespace ON\RateLimit;

use ON\Container\Init\Event\ContainerConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\RateLimit\Container\RateLimiterFactory;

class RateLimitExtension extends AbstractExtension
{
	public const ID = 'ratelimit';

	#[On]
	public function onContainerConfigure(ContainerConfigureEvent $event): void
	{
		$event->containerConfig->addFactories([
			RateLimiterInterface::class => RateLimiterFactory::class,
		]);
	}
}
