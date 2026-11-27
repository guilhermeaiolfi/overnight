<?php

declare(strict_types=1);

namespace ON\DataIntegration\Init\Event;

use ON\Data\Definition\Registry;
use ON\DataIntegration\DataExtension;
use ON\Init\Attribute\InitEvent;

#[InitEvent(owner: DataExtension::class, lazy: true)]
final class DataDefinitionConfigureEvent
{
	public function __construct(
		public readonly Registry $registry,
	) {
	}
}
