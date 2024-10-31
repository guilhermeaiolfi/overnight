<?php

declare(strict_types=1);

namespace ON\Config;

class LayoutConfig
{
	public function __construct(
		public ?string $renderer = null,
		public ?string $template = null
	) {

	}
}
