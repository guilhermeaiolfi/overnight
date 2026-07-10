<?php

declare(strict_types=1);

namespace ON\DataIntegration\Init\Event;

use ON\Data\Definition\Registry;

final class DataDefinitionConfigureEvent
{
	public function __construct(
		public readonly Registry $registry,
	) {
	}
}
