<?php

declare(strict_types=1);

namespace ON\Middleware\Init\Event;

use ON\Init\Attribute\InitEvent;
use ON\Middleware\PipelineExtension;
use Psr\Container\ContainerInterface;

#[InitEvent(owner: PipelineExtension::class)]
final class PipelineReadyEvent
{
	public function __construct(
		public PipelineExtension $pipeline,
		public ContainerInterface $container
	) {
	}
}
