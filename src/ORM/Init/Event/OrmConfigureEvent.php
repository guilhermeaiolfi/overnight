<?php

declare(strict_types=1);

namespace ON\ORM\Init\Event;

use ON\ORM\Definition\Registry;

final class OrmConfigureEvent
{
	public function __construct(
		public Registry $registry
	) {
	}
}
