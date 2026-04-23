<?php

declare(strict_types=1);

namespace ON\Middleware\Init\Event;

use ON\Middleware\PipelineExtension;
use Psr\Container\ContainerInterface;

final class PipelineReadyEvent
{
	public function __construct(
		public PipelineExtension $pipeline,
		public ContainerInterface $container
	) {
	}
}
